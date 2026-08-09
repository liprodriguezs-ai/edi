<?php

namespace Modules\Collections\Services;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\ReceiptSequence;
use Modules\Collections\Support\PaymentEarmarkRegistry;
use Modules\Housing\Models\Unit;
use Modules\Notification\Services\NotificationSettingService;
use Modules\Treasury\Models\BankAccount;
use QCH\Core\Support\TenantMoney;

/**
 * PaymentService — register and cancel payments with FIFO application.
 *
 * FIFO ORDER: credit_balance consumed first, then open charges by
 *   due_date ASC, issued_at ASC, id ASC.
 *
 * CONCURRENCY: charges, credit balance, and bank account are locked with
 *   lockForUpdate() before mutation to prevent double-application in concurrent
 *   requests for the same unit/account.
 *
 * MONEY: all amounts are minor units (integer centavos). Arithmetic is done with
 *   locked read-modify-write using raw ints — a DB::raw Expression cannot be routed
 *   through MoneyIntegerCast (it throws "Invalid data provided"), so we never do that.
 *
 * CAPTURE DEFAULT: the tesorero/admin captures payments. Cash → confirmed
 *   automatically (money is in hand). Transfer/deposit → confirmed by default
 *   in this build (tesorero captures after verifying). Resident self-submission
 *   → pending_validation (not yet implemented as a separate flow in v1).
 */
class PaymentService
{
    use NotifiesResidents;

    public function __construct(
        private readonly ChargeStatusService $chargeStatusService,
        private readonly ChargeGenerationService $chargeGenerationService,
    ) {}

    /**
     * Format a minor-units integer amount as a MXN currency string for notification copy.
     */
    private function formatMoney(int $amountRaw): string
    {
        return TenantMoney::format($amountRaw);
    }

    /**
     * Register a payment and apply it to charges (FIFO or manual).
     *
     * @param  array<string, mixed>  $data  Keys:
     *                                      unit_id (required), paid_at (required), total_amount (required, minor units int),
     *                                      payment_method_id (required), bank_account_id (required),
     *                                      reference (optional), physical_receipt_number (optional), notes (optional),
     *                                      payer_user_id (optional), manual_applications (optional — array of
     *                                      ['charge_id' => uuid, 'apply_amount' => int minor units]).
     * @param  bool  $silent  When true, skip resident-facing side effects (receipt PDF + notifications).
     *                        Used by bulk/historical import so migrating old payments does not email/notify
     *                        residents nor queue thousands of GenerateReceiptPdfJob.
     */
    public function register(array $data, bool $silent = false): Payment
    {
        $creditCreatedRaw = 0;

        // Earmarked payment: the handler owns the pinned applications, so explicit
        // manual_applications are mutually exclusive with an earmark.
        if (! empty($data['earmark_type'])) {
            if (! empty($data['manual_applications'])) {
                throw new \InvalidArgumentException('Un pago con destino etiquetado no acepta aplicaciones manuales explícitas.');
            }

            app(PaymentEarmarkRegistry::class)->handlerFor($data['earmark_type'])->validateSubmission($data);
        }

        $payment = DB::transaction(function () use ($data, &$creditCreatedRaw): Payment {
            $unitId = $data['unit_id'];
            $totalAmountRaw = (int) $data['total_amount'];

            // Generate receipt number: R-YYYY-NNNNNN sequential per tenant per year.
            $receiptNumber = $this->generateReceiptNumber($data['paid_at'] ?? now()->format('Y-m-d'));

            $payment = Payment::create([
                'unit_id' => $unitId,
                'payer_user_id' => $data['payer_user_id'] ?? null,
                'paid_at' => $data['paid_at'],
                'total_amount' => $totalAmountRaw,
                'payment_method_id' => $data['payment_method_id'],
                'bank_account_id' => $data['bank_account_id'],
                'reference' => $data['reference'] ?? null,
                'receipt_number' => $receiptNumber,
                'physical_receipt_number' => $data['physical_receipt_number'] ?? null,
                'payment_status_id' => PaymentStatus::CONFIRMED, // Default: tesorero/admin capture.
                'notes' => $data['notes'] ?? null,
                'earmark_type' => $data['earmark_type'] ?? null,
                'earmark_id' => $data['earmark_id'] ?? null,
            ]);

            // Apply (FIFO/manual/earmark-pinned), bank increment, unit recompute — shared
            // with validatePayment. An earmarked payment resolves its pinned applications
            // through the registered handler (admin direct capture path).
            $creditCreatedRaw = $this->applyConfirmedPayment(
                $payment,
                $payment->earmark_type !== null
                    ? $this->resolveEarmarkApplications($payment)
                    : ($data['manual_applications'] ?? null)
            );

            return $payment->load('applications', 'payment_status', 'payment_method');
        });

        // Bulk/historical import passes $silent=true to skip resident-facing side
        // effects: migrating old payments must not notify residents nor queue
        // thousands of receipt-PDF jobs (one per payment).
        if ($silent) {
            return $payment;
        }

        // §8.9: notify the unit's titular de pago that a payment was registered, and
        // (if any surplus became credit) that a credit balance is now available.
        // Dispatched AFTER commit so a notification error cannot roll back the payment.
        $titularId = $this->unitBillingUserId($payment->unit_id);
        $recipients = $titularId ? [$titularId] : [];

        // §8.9.1 (Phase 6): route through the configurable event so admins can toggle
        // channel/email from the UI. Context matches the payment-registered-direct Blade template.
        $residentName = $this->resolveResidentNameFromUserId($titularId);
        $unitLabel = $this->resolveUnitLabel($payment->unit_id);
        $amountFormatted = $this->formatMoney((int) $payment->getAttributes()['total_amount']);
        $paidAtFormatted = $this->formatDate($payment->paid_at);

        $this->notifyEvent(
            'payment_registered_direct',
            $recipients,
            [
                'resident_name' => $residentName,
                'receipt_number' => $payment->receipt_number ?? '',
                'amount' => $amountFormatted,
                'paid_at' => $paidAtFormatted,
                'unit_label' => $unitLabel,
                'url' => $this->residentPortalUrl(),
            ],
            'Pago registrado',
            "Se registró tu pago de {$amountFormatted} (folio {$payment->receipt_number}).",
            [
                'category' => NotificationCategory::COLLECTIONS,
                'click_action' => '/payment/'.$payment->id,
                'payment_id' => $payment->id,
                'unit_id' => $payment->unit_id,
            ],
        );

        if ($creditCreatedRaw > 0) {
            $this->notifyUsers(
                $recipients,
                'Saldo a favor disponible',
                'Tienes saldo a favor de '.$this->formatMoney($creditCreatedRaw)
                    .'. Se aplicará automáticamente en tu próximo cobro.',
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/statement',
                    'unit_id' => $payment->unit_id,
                ],
            );
        }

        // §8.5.3 / Flujo B: generate the receipt PDF and email it to the titular —
        // queued, never inside the money transaction.
        GenerateReceiptPdfJob::dispatch($payment->id);

        return $payment;
    }

    /**
     * Resident self-capture (spec §8.5.2): create a PENDING_VALIDATION payment WITHOUT
     * applying it to charges and WITHOUT touching the bank balance. No receipt_number is
     * generated yet (that happens on validation). The comprobante (receipt media) is
     * attached by the caller AFTER this returns (it requires the persisted model).
     *
     * Notifies the tesorero that a payment is waiting for validation.
     *
     * @param  array<string, mixed>  $data  unit_id, paid_at, total_amount, payment_method_id,
     *                                      bank_account_id (destination), reference?, payer_user_id?, notes?
     */
    public function submit(array $data): Payment
    {
        // Earmarked report: validate the target up-front so the resident gets a clear
        // 422 at capture time. The charge is only created later, on validation.
        // isSelfSubmission=true: this is the RESIDENT self-reporting their own payment.
        if (! empty($data['earmark_type'])) {
            app(PaymentEarmarkRegistry::class)->handlerFor($data['earmark_type'])->validateSubmission($data, true);
        }

        try {
            $payment = DB::transaction(function () use ($data): Payment {
                return Payment::create([
                    'unit_id' => $data['unit_id'],
                    'payer_user_id' => $data['payer_user_id'] ?? null,
                    'paid_at' => $data['paid_at'],
                    'total_amount' => (int) $data['total_amount'],
                    'payment_method_id' => $data['payment_method_id'],
                    'bank_account_id' => $data['bank_account_id'],
                    'reference' => $data['reference'] ?? null,
                    'receipt_number' => null, // assigned on validation
                    'physical_receipt_number' => $data['physical_receipt_number'] ?? null,
                    'payment_status_id' => PaymentStatus::PENDING_VALIDATION,
                    'notes' => $data['notes'] ?? null,
                    'earmark_type' => $data['earmark_type'] ?? null,
                    'earmark_id' => $data['earmark_id'] ?? null,
                ])->load('payment_status', 'payment_method');
            });
        } catch (UniqueConstraintViolationException) {
            // Two concurrent reports for the same earmark: the partial unique index
            // (payment_one_pending_per_earmark) is the race-proof lock; translate the
            // loser's violation into the same friendly 422 the handler's check gives.
            throw new \InvalidArgumentException('Ya hay un pago reportado en revisión para esta aportación.');
        }

        // §8.5.2: notify configured board recipients that a resident payment awaits validation.
        // Resolve once and reuse for both recipient computation and notifyEvent dispatch
        // (arch W1 — eliminates the double resolve() that boardRecipientsForEvent + notifyEvent caused).
        $resolvedPaymentRegistered = app(NotificationSettingService::class)->resolve('payment_registered');
        if ($resolvedPaymentRegistered['is_active']) {
            $billingOwnerId = $this->unitBillingUserId($payment->unit_id);
            $residentName = $this->resolveResidentNameFromUserId($billingOwnerId);
            $unitLabel = $this->resolveUnitLabel($payment->unit_id);
            $amountFormatted = $this->formatMoney((int) $payment->getAttributes()['total_amount']);
            $methodName = $payment->payment_method?->name ?? '';
            $paidAtFormatted = $this->formatDate($payment->paid_at);
            $url = $this->residentPortalUrl();

            $this->notifyEvent(
                'payment_registered',
                $this->boardRecipientsForEvent('payment_registered', $resolvedPaymentRegistered),
                [
                    'resident_name' => $residentName,
                    'unit_label' => $unitLabel,
                    'amount' => $amountFormatted,
                    'method' => $methodName,
                    'paid_at' => $paidAtFormatted,
                    'url' => $url,
                ],
                'Pago por validar',
                "Residente {$residentName} ({$unitLabel}) capturó un pago de {$amountFormatted}. Revísalo.",
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/payment/'.$payment->id,
                    'payment_id' => $payment->id,
                    'unit_id' => $payment->unit_id,
                ],
                $resolvedPaymentRegistered,
            );
        }

        return $payment;
    }

    /**
     * Tesorero/Admin validates a pending payment (spec §8.5.3): transition to CONFIRMED,
     * NOW apply FIFO, increment the bank balance, and generate the receipt_number — all
     * via the SAME apply path as register(). Notifies the resident it was confirmed.
     *
     * @throws \InvalidArgumentException If the payment is not in PENDING_VALIDATION.
     */
    public function validatePayment(Payment $payment): Payment
    {
        $creditCreatedRaw = 0;

        $confirmed = DB::transaction(function () use ($payment, &$creditCreatedRaw): Payment {
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_status_id !== PaymentStatus::PENDING_VALIDATION) {
                throw new \InvalidArgumentException('El pago no está pendiente de validación.');
            }

            $paidAt = $locked->getAttributes()['paid_at'] ?? now()->format('Y-m-d');
            $locked->receipt_number = $this->generateReceiptNumber((string) $paidAt);
            $locked->payment_status_id = PaymentStatus::CONFIRMED;
            $locked->save();

            // Apply (FIFO, or earmark-pinned), bank increment, unit recompute — identical
            // to register(). A handler exception rolls back → the payment stays pending.
            $creditCreatedRaw = $this->applyConfirmedPayment($locked, $this->resolveEarmarkApplications($locked));

            return $locked->load('applications', 'payment_status', 'payment_method');
        });

        // §8.5.3: notify the resident that their submitted payment was confirmed.
        $titularId = $this->unitBillingUserId($confirmed->unit_id);
        $recipients = $titularId ? [$titularId] : [];
        $residentNameV = $this->resolveResidentNameFromUserId($titularId);
        $unitLabelV = $this->resolveUnitLabel($confirmed->unit_id);
        $amountFormattedV = $this->formatMoney((int) $confirmed->getAttributes()['total_amount']);
        $paidAtFormattedV = $this->formatDate($confirmed->paid_at);
        $urlV = $this->residentPortalUrl();

        $this->notifyEvent(
            'payment_accepted',
            $recipients,
            [
                'resident_name' => $residentNameV,
                'receipt_number' => $confirmed->receipt_number,
                'amount' => $amountFormattedV,
                'paid_at' => $paidAtFormattedV,
                'unit_label' => $unitLabelV,
                'url' => $urlV,
            ],
            'Pago confirmado',
            "Tu pago de {$amountFormattedV} fue validado (folio {$confirmed->receipt_number}).",
            [
                'category' => NotificationCategory::COLLECTIONS,
                'click_action' => '/payment/'.$confirmed->id,
                'payment_id' => $confirmed->id,
                'unit_id' => $confirmed->unit_id,
            ],
        );

        if ($creditCreatedRaw > 0) {
            $this->notifyUsers(
                $recipients,
                'Saldo a favor disponible',
                'Tienes saldo a favor de '.$this->formatMoney($creditCreatedRaw)
                    .'. Se aplicará automáticamente en tu próximo cobro.',
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/statement',
                    'unit_id' => $confirmed->unit_id,
                ],
            );
        }

        // §8.5.3: "Se envía recibo PDF por email" — queued, after the transaction.
        GenerateReceiptPdfJob::dispatch($confirmed->id);

        return $confirmed;
    }

    /**
     * Tesorero/Admin rejects a pending payment (spec §8.5.3): transition to CANCELLED with
     * a reason. No FIFO application, no bank mutation (the payment was never confirmed).
     * Notifies the resident with the reason so they can resubmit a corrected comprobante.
     *
     * Note: PaymentStatus has no separate REJECTED key (confirmed/pending_validation/
     * cancelled only), so a rejection reuses CANCELLED and stores the reason in
     * cancellation_reason — matching the existing cancel() semantics.
     *
     * @throws \InvalidArgumentException If the payment is not in PENDING_VALIDATION.
     */
    public function reject(Payment $payment, string $reason): Payment
    {
        $rejected = DB::transaction(function () use ($payment, $reason): Payment {
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_status_id !== PaymentStatus::PENDING_VALIDATION) {
                throw new \InvalidArgumentException('Solo se puede rechazar un pago pendiente de validación.');
            }

            $locked->payment_status_id = PaymentStatus::CANCELLED;
            $locked->cancellation_reason = $reason;
            $locked->save();

            return $locked->load('payment_status');
        });

        // §8.5.3: notify the resident with the rejection reason so they can resubmit.
        $titularId = $this->unitBillingUserId($rejected->unit_id);
        $residentNameR = $this->resolveResidentNameFromUserId($titularId);
        $unitLabelR = $this->resolveUnitLabel($rejected->unit_id);
        $amountFormattedR = $this->formatMoney((int) $rejected->getAttributes()['total_amount']);
        $urlR = $this->residentPortalUrl();

        $this->notifyEvent(
            'payment_rejected',
            array_filter([$titularId]),
            [
                'resident_name' => $residentNameR,
                'amount' => $amountFormattedR,
                'reason' => $reason,
                'unit_label' => $unitLabelR,
                'url' => $urlR,
            ],
            'Pago rechazado',
            "Tu pago de {$amountFormattedR} no pudo ser validado: {$reason} Puedes subir un comprobante corregido.",
            [
                'category' => NotificationCategory::COLLECTIONS,
                'click_action' => '/payment/'.$rejected->id,
                'payment_id' => $rejected->id,
                'unit_id' => $rejected->unit_id,
            ],
        );

        return $rejected;
    }

    /**
     * Apply a CONFIRMED payment to charges: FIFO (or manual), surplus → credit, bank
     * increment, unit-aggregate recompute. Shared by register() and validatePayment() so
     * the money logic exists in exactly one place.
     *
     * MUST run inside the payment's transaction. The payment row must already be persisted
     * with status CONFIRMED and its bank_account_id/unit_id set.
     *
     * @param  array<int, array{charge_id: string, apply_amount: int}>|null  $manualApplications
     * @return int Minor units of surplus that became credit (0 when none).
     */
    private function applyConfirmedPayment(Payment $payment, ?array $manualApplications): int
    {
        $unitId = $payment->unit_id;
        $totalAmountRaw = (int) $payment->getAttributes()['total_amount'];

        $remaining = $totalAmountRaw;

        if (! empty($manualApplications)) {
            $remaining = $this->applyManual($payment, $manualApplications, $remaining, $unitId);
        } else {
            $remaining = $this->applyFifo($payment, $unitId, $remaining);
        }

        $creditCreatedRaw = 0;
        if ($remaining > 0) {
            $this->addCredit($unitId, $remaining);
            $creditCreatedRaw = $remaining;
        }

        // Confirmed payment → the cash lands in the destination bank account.
        $this->incrementBankBalance($payment->bank_account_id, $totalAmountRaw);

        // Recompute unit aggregate status after all applications.
        $this->chargeStatusService->recomputeUnitAggregate($unitId);

        return $creditCreatedRaw;
    }

    /**
     * Resolve the pinned applications for an earmarked payment via its registered
     * handler; null for a normal payment (→ FIFO, identical to the pre-earmark flow).
     *
     * MUST run inside the payment's transaction: the handler may create the
     * destination charge (e.g. the eventual project charge).
     *
     * @return array<int, array{charge_id: string, apply_amount: int}>|null
     */
    private function resolveEarmarkApplications(Payment $payment): ?array
    {
        if ($payment->earmark_type === null) {
            return null;
        }

        return app(PaymentEarmarkRegistry::class)
            ->handlerFor($payment->earmark_type)
            ->resolveApplications($payment);
    }

    /**
     * Cancel a payment: reverse all applications, restore consumed credit, remove
     * generated credit, and decrement bank balance.
     *
     * LEDGER INVARIANT (must hold after ANY register/cancel sequence for a unit):
     *   Σ(charge.paid_amount) + credit_balance.amount == Σ(confirmed payment.total_amount)
     *
     * Applications carry a `source`:
     *   - cash   → funded by THIS payment's own cash.
     *   - credit → funded by pre-existing credit this payment CONSUMED.
     *
     * On cancel we:
     *   - For every application: decrement charge.paid_amount.
     *   - For credit-funded applications: RESTORE the consumed credit (addCredit).
     *   - Remove the credit this payment GENERATED at register time:
     *       generatedCredit = payment.total_amount − Σ(cash-funded applications)
     *     (cash-funded applications + generated credit together account for the
     *      payment's own cash; credit-funded applications used OTHER money).
     *
     * @param  string  $reason  Cancellation reason (required).
     */
    public function cancel(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            // CONCURRENCY (finding sec-money-02): re-read the row UNDER LOCK and validate
            // the state on the locked copy — the in-memory model passed by the controller
            // can be stale, letting two concurrent cancels both pass the check and reverse
            // the bank/ledger twice. Same pattern as validatePayment()/reject().
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            $cancelledStatus = PaymentStatus::findOrFail(PaymentStatus::CANCELLED);

            if ($payment->payment_status_id === $cancelledStatus->id) {
                throw new \InvalidArgumentException('El pago ya está cancelado.');
            }

            $wasConfirmed = $payment->payment_status_id === PaymentStatus::CONFIRMED;
            $totalAmountRaw = (int) $payment->getAttributes()['total_amount'];

            // Reverse all applications.
            $affectedChargeIds = [];
            $cashApplied = 0;

            $applications = PaymentApplication::where('payment_id', $payment->id)->lockForUpdate()->get();

            foreach ($applications as $application) {
                $appliedRaw = (int) $application->getAttributes()['applied_amount'];
                $source = $application->getAttributes()['source'] ?? PaymentApplication::SOURCE_CASH;
                $affectedChargeIds[] = $application->charge_id;

                // Decrement charge.paid_amount (locked read-modify-write with raw int).
                $this->adjustChargePaidAmount($application->charge_id, -$appliedRaw);

                if ($source === PaymentApplication::SOURCE_CREDIT) {
                    // This application had consumed pre-existing credit; give it back.
                    $this->addCredit($payment->unit_id, $appliedRaw);
                } else {
                    $cashApplied += $appliedRaw;
                }

                $application->delete();
            }

            // Remove the credit this payment GENERATED at register time. The payment's
            // own cash went to cash-funded applications and to generated credit; the
            // remainder of total over cash-funded applications is exactly that credit.
            // If a later payment already CONSUMED that credit, the credit balance can't
            // cover it — claw the shortfall back from the charges that consumed it so the
            // ledger invariant holds and no balance goes negative.
            $generatedCredit = $totalAmountRaw - $cashApplied;
            if ($generatedCredit > 0) {
                $shortfall = $this->subtractCredit($payment->unit_id, $generatedCredit);
                if ($shortfall > 0) {
                    $clawedChargeIds = $this->clawbackConsumedCredit($payment->unit_id, $payment->id, $shortfall);
                    foreach ($clawedChargeIds as $clawedChargeId) {
                        $affectedChargeIds[] = $clawedChargeId;
                    }
                }
            }

            // Mark payment cancelled.
            $payment->payment_status_id = $cancelledStatus->id;
            $payment->cancellation_reason = $reason;
            $payment->save();

            // Recompute statuses for affected charges and their batches.
            $affectedBatchIds = [];
            foreach (array_unique($affectedChargeIds) as $chargeId) {
                $charge = Charge::find($chargeId);
                if ($charge) {
                    $charge->refresh();
                    $this->chargeStatusService->recomputeCharge($charge);
                    if ($charge->charge_batch_id) {
                        $affectedBatchIds[$charge->charge_batch_id] = true;
                    }
                }
            }

            // Recompute unit aggregate.
            $this->chargeStatusService->recomputeUnitAggregate($payment->unit_id);

            // Recompute batch progress.
            foreach (array_keys($affectedBatchIds) as $batchId) {
                $batch = ChargeBatch::find($batchId);
                if ($batch) {
                    $this->chargeGenerationService->recomputeBatchProgress($batch);
                }
            }

            // Reverse bank balance (confirmed payments only).
            if ($wasConfirmed) {
                $this->decrementBankBalance($payment->bank_account_id, $totalAmountRaw);
            }

            return $payment->refresh()->load('payment_status');
        });
    }

    /**
     * FIFO application: consume credit first, then charges by due_date/issued_at/id.
     *
     * Row-lock the unit's charges (lockForUpdate) before mutation.
     *
     * @param  int  $remaining  Minor units remaining.
     * @return int Remaining minor units after all applications (0 if fully applied or surplus).
     */
    private function applyFifo(Payment $payment, string $unitId, int $remaining): int
    {
        // 1. Consume existing credit balance first (applied to the oldest charges).
        $creditRow = CreditBalance::where('unit_id', $unitId)->lockForUpdate()->first();
        $creditAvailable = $creditRow ? (int) $creditRow->getAttributes()['amount'] : 0;

        if ($creditRow && $creditAvailable > 0) {
            $this->applyCreditToCharges($payment, $unitId, $creditRow, $creditAvailable);
        }

        // 2. Apply remaining payment amount to open charges (FIFO).
        $openCharges = $this->openChargesFor($unitId);

        foreach ($openCharges as $charge) {
            if ($remaining <= 0) {
                break;
            }

            $chargeRemaining = $this->chargeRemaining($charge);
            if ($chargeRemaining <= 0) {
                continue;
            }

            $toApply = min($remaining, $chargeRemaining);

            $this->applyToCharge($payment, $charge, $toApply, PaymentApplication::SOURCE_CASH);

            $remaining -= $toApply;
        }

        return $remaining;
    }

    /**
     * Auto-apply a unit's available credit balance to its open charges (Flujo C.5,
     * spec §8.7.3.4: "saldo a favor se aplica primero en futuras cobros automáticamente").
     *
     * Called by ChargeGenerationService right after generating new charges for a unit,
     * INSIDE the generation transaction. The resulting payment_application rows carry
     * payment_id NULL + source=credit (no payment is behind a system credit application).
     * Idempotent: with no credit or no open charges it is a no-op.
     *
     * @return int Minor units of credit consumed (0 when none).
     */
    public function applyUnitCreditFifo(string $unitId): int
    {
        $creditRow = CreditBalance::where('unit_id', $unitId)->lockForUpdate()->first();
        $creditAvailable = $creditRow ? (int) $creditRow->getAttributes()['amount'] : 0;

        if (! $creditRow || $creditAvailable <= 0) {
            return 0;
        }

        return $this->applyCreditToCharges(null, $unitId, $creditRow, $creditAvailable);
    }

    /**
     * Apply an existing credit balance to open charges (FIFO), consuming the credit.
     *
     * Creates payment_application rows stamped source=credit (funded by consumed credit,
     * NOT the payment's own cash) and reduces the running credit balance. $payment is
     * null for system auto-applications at charge generation (payment_id NULL).
     *
     * @return int Minor units of credit consumed.
     */
    private function applyCreditToCharges(
        ?Payment $payment,
        string $unitId,
        CreditBalance $creditRow,
        int $creditAvailable,
    ): int {
        $openCharges = $this->openChargesFor($unitId);
        $creditUsed = 0;

        foreach ($openCharges as $charge) {
            if ($creditAvailable <= 0) {
                break;
            }

            $chargeRemaining = $this->chargeRemaining($charge);
            if ($chargeRemaining <= 0) {
                continue;
            }

            $toApply = min($creditAvailable, $chargeRemaining);

            $this->applyToCharge($payment, $charge, $toApply, PaymentApplication::SOURCE_CREDIT);

            $creditAvailable -= $toApply;
            $creditUsed += $toApply;
        }

        if ($creditUsed > 0) {
            $current = (int) $creditRow->getAttributes()['amount'];
            $creditRow->amount = max(0, $current - $creditUsed);
            $creditRow->save();
        }

        return $creditUsed;
    }

    /**
     * Manual application: apply specific amounts to specific charges.
     * Validates that sum(applied) <= total_amount; leftover → credit.
     *
     * @param  array<int, array{charge_id: string, apply_amount: int}>  $applications
     * @param  int  $remaining  Total amount in minor units.
     * @return int Leftover minor units for credit.
     */
    private function applyManual(Payment $payment, array $applications, int $remaining, string $unitId): int
    {
        $sumApplied = array_sum(array_column($applications, 'apply_amount'));

        if ($sumApplied > $remaining) {
            throw new \InvalidArgumentException(
                "La suma de aplicaciones manuales ({$sumApplied}) supera el monto del pago ({$remaining})."
            );
        }

        $chargeIds = array_column($applications, 'charge_id');

        // Lock all affected charges.
        $charges = Charge::whereIn('id', $chargeIds)
            ->where('unit_id', $unitId)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($applications as $application) {
            $chargeId = $application['charge_id'];
            $toApply = (int) $application['apply_amount'];

            if ($toApply <= 0) {
                continue;
            }

            $charge = $charges->get($chargeId);
            if (! $charge || $charge->is_cancelled) {
                continue;
            }

            $this->applyToCharge($payment, $charge, $toApply, PaymentApplication::SOURCE_CASH);

            $remaining -= $toApply;
        }

        return $remaining;
    }

    /**
     * Apply a single amount to a single charge: create the application row, bump
     * paid_amount, recompute the charge status and its batch progress.
     *
     * @param  Payment|null  $payment  Null for system credit auto-applications at charge
     *                                 generation (payment_id NULL, source=credit).
     * @param  string  $source  PaymentApplication::SOURCE_CASH (payment's own cash) or
     *                          SOURCE_CREDIT (consumed pre-existing credit). Drives correct
     *                          credit restoration on cancel.
     */
    private function applyToCharge(?Payment $payment, Charge $charge, int $toApply, string $source): void
    {
        PaymentApplication::create([
            'payment_id' => $payment?->id,
            'charge_id' => $charge->id,
            'applied_amount' => $toApply,
            'source' => $source,
        ]);

        $this->adjustChargePaidAmount($charge->id, $toApply);
        $charge->refresh();

        $this->chargeStatusService->recomputeCharge($charge);

        if ($charge->charge_batch_id) {
            $batch = ChargeBatch::find($charge->charge_batch_id);
            if ($batch) {
                $this->chargeGenerationService->recomputeBatchProgress($batch);
            }
        }
    }

    /**
     * Open (non-cancelled, not fully paid) charges for a unit, FIFO-ordered and row-locked.
     *
     * @return Collection<int, Charge>
     */
    private function openChargesFor(string $unitId): Collection
    {
        // A charge is open while paid_amount < (expected_amount - discount_amount + surcharge_amount).
        // The discount (condonación, §8.2) reduces what FIFO must still cover; the late-fee
        // surcharge (§3.6) increases it. This MUST mirror ChargeStatusService::effectiveExpected
        // exactly — a charge with an unpaid surcharge stays open so FIFO can collect principal
        // PLUS recargo (raw-int columns; the cast is never routed through DB::raw — read-side WHERE).
        return Charge::query()
            ->where('unit_id', $unitId)
            ->where('is_cancelled', false)
            ->whereRaw('paid_amount < (expected_amount - discount_amount + surcharge_amount)')
            ->orderBy('due_date')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Remaining unpaid amount of a charge in minor units.
     *
     * Uses the EFFECTIVE expected (expected_amount − discount_amount, §8.2) so a
     * condoned charge needs less cash to complete and never absorbs more than owed.
     */
    private function chargeRemaining(Charge $charge): int
    {
        $effectiveExpected = $this->chargeStatusService->effectiveExpected($charge);
        $paidRaw = (int) $charge->getAttributes()['paid_amount'];

        return max(0, $effectiveExpected - $paidRaw);
    }

    /**
     * Add minor units to a unit's credit balance (upsert), with a row lock.
     */
    private function addCredit(string $unitId, int $amountRaw): void
    {
        $creditRow = CreditBalance::where('unit_id', $unitId)->lockForUpdate()->first();

        if ($creditRow) {
            $current = (int) $creditRow->getAttributes()['amount'];
            $creditRow->amount = $current + $amountRaw;
            $creditRow->save();
        } else {
            $creditRow = new CreditBalance;
            $creditRow->unit_id = $unitId;
            $creditRow->amount = $amountRaw;
            $creditRow->save();
        }
    }

    /**
     * Remove minor units from a unit's credit balance (floor at 0), with a row lock.
     *
     * @return int The shortfall — minor units that could NOT be subtracted because the
     *             credit balance was insufficient (0 when fully covered). The caller may
     *             claw this back from charges that already consumed the credit.
     */
    private function subtractCredit(string $unitId, int $amountRaw): int
    {
        $creditRow = CreditBalance::where('unit_id', $unitId)->lockForUpdate()->first();
        if (! $creditRow) {
            return $amountRaw;
        }

        $current = (int) $creditRow->getAttributes()['amount'];
        $subtracted = min($current, $amountRaw);
        $creditRow->amount = $current - $subtracted;
        $creditRow->save();

        return $amountRaw - $subtracted;
    }

    /**
     * Claw back credit that was generated by a now-cancelled payment but already CONSUMED
     * by later payments (their source=credit applications). Reduces those charges'
     * paid_amount and removes/shrinks the consuming applications so the ledger invariant
     * holds without driving any balance negative.
     *
     * Walks credit-funded applications for the unit (excluding the cancelled payment),
     * newest first, until the shortfall is absorbed.
     *
     * @return array<int, string> Charge IDs whose paid_amount was reduced (for status recompute).
     */
    private function clawbackConsumedCredit(string $unitId, string $cancelledPaymentId, int $shortfall): array
    {
        $clawedChargeIds = [];

        // System auto-applications (charge generation) have payment_id NULL — they also
        // consumed credit and MUST be clawback candidates ('payment_id != x' alone would
        // silently exclude NULL rows in SQL).
        $creditApplications = PaymentApplication::query()
            ->where('source', PaymentApplication::SOURCE_CREDIT)
            ->where(function ($q) use ($cancelledPaymentId): void {
                $q->whereNull('payment_id')->orWhere('payment_id', '!=', $cancelledPaymentId);
            })
            ->whereHas('charge', fn ($q) => $q->where('unit_id', $unitId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($creditApplications as $application) {
            if ($shortfall <= 0) {
                break;
            }

            $appliedRaw = (int) $application->getAttributes()['applied_amount'];
            $toClaw = min($shortfall, $appliedRaw);

            $this->adjustChargePaidAmount($application->charge_id, -$toClaw);
            $clawedChargeIds[] = $application->charge_id;

            if ($toClaw >= $appliedRaw) {
                $application->delete();
            } else {
                $application->applied_amount = $appliedRaw - $toClaw;
                $application->save();
            }

            $shortfall -= $toClaw;
        }

        return $clawedChargeIds;
    }

    /**
     * Adjust a charge's paid_amount by a signed delta (minor units), with a row lock.
     *
     * Uses a locked read-modify-write with raw integers instead of a DB::raw
     * Expression, because routing an Expression through MoneyIntegerCast throws.
     * The lockForUpdate keeps concurrent applications serialized per charge.
     */
    private function adjustChargePaidAmount(string $chargeId, int $deltaRaw): void
    {
        $charge = Charge::where('id', $chargeId)->lockForUpdate()->first();
        if (! $charge) {
            return;
        }

        $current = (int) $charge->getAttributes()['paid_amount'];
        $charge->paid_amount = max(0, $current + $deltaRaw);
        $charge->save();
    }

    /**
     * Increment bank account current_balance (confirmed payment received).
     */
    private function incrementBankBalance(string $bankAccountId, int $amountRaw): void
    {
        $this->adjustBankBalance($bankAccountId, $amountRaw);
    }

    /**
     * Decrement bank account current_balance (payment cancelled).
     */
    private function decrementBankBalance(string $bankAccountId, int $amountRaw): void
    {
        $this->adjustBankBalance($bankAccountId, -$amountRaw);
    }

    /**
     * Adjust a bank account's current_balance by a signed delta (minor units), with a row lock.
     * Read-modify-write with raw integers to avoid routing a DB::raw Expression through MoneyIntegerCast.
     */
    private function adjustBankBalance(string $bankAccountId, int $deltaRaw): void
    {
        $account = BankAccount::where('id', $bankAccountId)->lockForUpdate()->first();
        if (! $account) {
            return;
        }

        $current = (int) $account->getAttributes()['current_balance'];
        $account->current_balance = $current + $deltaRaw;
        $account->save();
    }

    /**
     * Resolve the display name for the unit's billing-owner user.
     *
     * Bypasses UserTenantScope because the User table is central: the scope would
     * otherwise filter it out when the user is not mapped into the tenant context
     * (edge case in tests, but common in the real call stack).
     */
    private function resolveResidentNameFromUserId(?string $userId): string
    {
        if (! $userId) {
            return 'Residente';
        }

        $user = User::withoutGlobalScope(UserTenantScope::class)->withTrashed()->find($userId);

        return $user ? (trim($user->full_name) ?: ($user->name ?? 'Residente')) : 'Residente';
    }

    /**
     * Resolve the human-readable unit label (full_identifier) for notification copy.
     * Eager-loads unitType + tower so the accessor never triggers additional queries.
     */
    private function resolveUnitLabel(string $unitId): string
    {
        $unit = Unit::with(['unitType', 'tower'])->find($unitId);

        return $unit?->full_identifier ?? 'Unidad';
    }

    /**
     * Frontend portal base URL, trimmed and pointing at the cobranza section.
     * Callers embed this as the `url` context key so email CTAs link directly.
     */
    private function residentPortalUrl(): string
    {
        return rtrim((string) config('app.frontend_url', ''), '/').'/cobranza';
    }

    /**
     * Format a date for human-readable notification copy (d/m/Y).
     */
    private function formatDate(mixed $date): string
    {
        if (! $date) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d/m/Y');
    }

    /**
     * Generate a unique receipt number: R-YYYY-NNNNNN (sequential per tenant per year).
     *
     * CONCURRENCY (findings collections-08 / sec-money-03): the sequence comes from the
     * receipt_sequence counter row, incremented under lockForUpdate inside the payment's
     * transaction — two concurrent registers serialize on the row lock and can never
     * pick the same folio. No COUNT/MAX scan per payment.
     *
     * @param  string  $date  Date string (Y-m-d) to extract the year from.
     */
    private function generateReceiptNumber(string $date): string
    {
        $year = (int) substr($date, 0, 4);

        return sprintf('R-%04d-%06d', $year, $this->nextReceiptSequence($year));
    }

    /**
     * Next NNNNNN for the tenant/year counter row, read-modify-write under a row lock.
     * MUST run inside the surrounding payment transaction.
     */
    private function nextReceiptSequence(int $year): int
    {
        $row = ReceiptSequence::where('year', $year)->lockForUpdate()->first();

        if (! $row) {
            $this->createReceiptSequenceRow($year);
            $row = ReceiptSequence::where('year', $year)->lockForUpdate()->firstOrFail();
        }

        $next = ((int) $row->getAttributes()['last_number']) + 1;
        $row->last_number = $next;
        $row->save();

        return $next;
    }

    /**
     * Create the counter row for a year, seeding last_number from the highest existing
     * folio (one-time backfill for payments issued before receipt_sequence existed —
     * the fixed-width zero-padded format makes lexicographic MAX == numeric MAX).
     *
     * The INSERT runs in a nested transaction (Postgres SAVEPOINT) so a concurrent
     * winner's unique violation aborts only the savepoint, not the whole payment
     * transaction; the caller then re-reads the winning row under lock.
     */
    private function createReceiptSequenceRow(int $year): void
    {
        $maxExisting = Payment::withTrashed()
            ->where('receipt_number', 'like', sprintf('R-%04d-%%', $year))
            ->max('receipt_number');

        $lastNumber = $maxExisting ? (int) substr((string) $maxExisting, -6) : 0;

        try {
            DB::transaction(function () use ($year, $lastNumber): void {
                ReceiptSequence::create([
                    'year' => $year,
                    'last_number' => $lastNumber,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the row first — the caller re-reads it.
        }
    }
}
