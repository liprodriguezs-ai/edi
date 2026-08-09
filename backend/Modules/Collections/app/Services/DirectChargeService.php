<?php

namespace Modules\Collections\Services;

use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\Payment;

/**
 * DirectChargeService — atomic "cargo puntual con cobro inmediato" (mostrador).
 *
 * In ONE DB::transaction it (1) creates an ad-hoc Charge (no batch, no policy)
 * and (2) registers a CONFIRMED Payment via PaymentService::register() whose
 * money is PINNED to that charge through manual_applications — so FIFO can
 * NEVER divert the counter payment to old maintenance debt.
 *
 * DECISION RULE vs the earmark family (PaymentEarmarkHandler/PaymentEarmarkRegistry):
 * does the payment fund an EXISTING domain entity that knows how to create its own
 * charge (project contribution today; amenity reservations, packages tomorrow)?
 * → earmarked payment (payment/register or payment/submit + earmark_type/earmark_id).
 * Is it a loose counter collection with a catalog concept or free text and no
 * domain entity to point at? → DirectChargeService.
 *
 * REGLA DE ORO: a direct charge is ALWAYS born paid and pinned, or it is not born.
 * There is no "generate now, pay later" gap for a direct charge — that gap is the
 * only window in which FIFO could steal the money.
 *
 * Unlike ChargeController::performStore, this NEVER auto-consumes the unit's
 * credit_balance (no applyUnitCreditFifo): the resident pays fresh for this service,
 * so the counter amount equals the concept amount, never netted against prior credit.
 */
class DirectChargeService
{
    public function __construct(
        private readonly ChargeStatusService $chargeStatusService,
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Create an ad-hoc charge and register + pin a confirmed payment to it, atomically.
     *
     * @param  array<string, mixed>  $data  Keys:
     *                                      unit_id (required, uuid), expected_amount (required, minor units int),
     *                                      payment_method_id (required), bank_account_id (required),
     *                                      charge_concept_id (nullable) OR concept_free_text (nullable) — when a
     *                                      free-text motive is given the charge uses the seeded "OTHER" concept and
     *                                      stores the text in charge.description,
     *                                      paid_at/issued_at/due_date (optional, default today),
     *                                      reference/physical_receipt_number/notes/payer_user_id (optional).
     * @return array{charge: Charge, payment: Payment}
     *
     * @throws \InvalidArgumentException When a free-text motive is used but the tenant has no "OTHER" concept seeded.
     */
    public function create(array $data): array
    {
        $expectedRaw = (int) $data['expected_amount'];
        $today = now()->toDateString();

        $freeText = isset($data['concept_free_text']) ? trim((string) $data['concept_free_text']) : '';

        if ($freeText !== '') {
            $conceptId = $this->resolveOtherConceptId();
            $description = $freeText;
        } else {
            $conceptId = $data['charge_concept_id'];
            $description = null;
        }

        return DB::transaction(function () use ($data, $expectedRaw, $today, $conceptId, $description): array {
            // (a) Ad-hoc charge: no batch, no policy. Ledger/FSM columns are service-owned.
            $charge = new Charge;
            $charge->fill([
                'charge_concept_id' => $conceptId,
                'unit_id' => $data['unit_id'],
                'expected_amount' => $expectedRaw,
                'description' => $description,
                'issued_at' => $data['issued_at'] ?? $today,
                'due_date' => $data['due_date'] ?? $today,
                'grace_days' => 0,
            ]);
            $charge->charge_batch_id = null;
            $charge->charge_policy_id = null;
            $charge->paid_amount = 0;
            $charge->is_cancelled = false;

            $charge->charge_status_id = $this->chargeStatusService->resolveStatusKey($charge);
            $charge->save();

            // (b) Register the payment PINNED to the new charge (manual application =
            // no FIFO). register() confirms it, generates the receipt_number, increments
            // the bank balance and recomputes the unit worst-wins aggregate.
            $payment = $this->paymentService->register([
                'unit_id' => $data['unit_id'],
                'paid_at' => $data['paid_at'] ?? $today,
                'total_amount' => $expectedRaw,
                'payment_method_id' => $data['payment_method_id'],
                'bank_account_id' => $data['bank_account_id'],
                'payer_user_id' => $data['payer_user_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'physical_receipt_number' => $data['physical_receipt_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'manual_applications' => [
                    ['charge_id' => $charge->id, 'apply_amount' => $expectedRaw],
                ],
            ]);

            return [
                'charge' => $charge->refresh(),
                'payment' => $payment,
            ];
        });
    }

    /**
     * Resolve the tenant's seeded "OTHER" service concept (code = OTHER) for free-text
     * motives. Tenant-scoped via BelongsToTenant. Seeded by TenantCatalogSeeder; a tenant
     * created before this feature and not backfilled has none → clear 422 upstream.
     *
     * @throws \InvalidArgumentException
     */
    private function resolveOtherConceptId(): string
    {
        $concept = ChargeConcept::where('code', 'OTHER')->first();

        if (! $concept) {
            throw new \InvalidArgumentException(
                'No existe el concepto "Otro" para capturar un motivo libre. Contacta al administrador.'
            );
        }

        return $concept->id;
    }
}
