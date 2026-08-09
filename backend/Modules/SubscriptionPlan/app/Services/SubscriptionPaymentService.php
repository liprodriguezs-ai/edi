<?php

namespace Modules\SubscriptionPlan\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;

/**
 * Manual (transfer/cash) subscription-payment flow for super-admins.
 *
 * A super-admin records that a cluster paid out-of-band (bank transfer or cash).
 * ONE manual payment covers EVERY paid package the cluster has contracted: the
 * service writes a central ledger row PER package, extends each package's
 * entitlement by one billing interval, and unblocks a previously-blocked tenant
 * — all in ONE transaction so the ledger and the entitlements can never drift.
 *
 * A paid manual payment is recorded with source=MANUAL (NOT super_admin_free):
 * real money changed hands, so the entitlement carries an expiry and is NEVER
 * shown as "Cortesía gratuita". Courtesy packages (active source=super_admin_free)
 * are EXCLUDED from the charge — they are $0 and perpetual.
 *
 * Octane-safe: no per-instance state; now() is read per call so a long-lived
 * worker never anchors a payment to a stale "now".
 *
 * It also owns the payment-method switch (card↔transfer/cash). Moving OFF card
 * cancels the cluster's active Stripe subscription — either at period end
 * ($subscription->cancel()) or immediately ($subscription->cancelNow()) — so a
 * cluster paying manually is no longer also billed by Stripe.
 */
class SubscriptionPaymentService
{
    public const CANCEL_AT_PERIOD_END = 'at_period_end';

    public const CANCEL_NOW = 'now';

    public const CANCEL_MODES = [self::CANCEL_AT_PERIOD_END, self::CANCEL_NOW];

    public function __construct(
        private EntitlementService $entitlementService,
    ) {}

    /**
     * Record ONE manual payment that covers SEVERAL contracted packages of a
     * cluster at once, extending each package's entitlement by one interval.
     *
     * Contract — $lines is a list of per-package lines, each its own amount
     * (minor units) and optional interval (the admin edits the amount; the
     * default comes from the plan price, computed by the caller):
     *
     *   [['package' => BillingPackage, 'amount' => int, 'interval' => ?string], ...]
     *
     * For EACH line a separate ledger row (subscription_payment) is written and
     * its entitlement is granted/extended with source=MANUAL (paid, NOT
     * courtesy). Per-package handling inside the single transaction:
     *   - COURTESY (active source=super_admin_free) → EXCLUDED: skipped, never
     *     charged ($0, perpetual). It is not an error to pass one; it is dropped.
     *   - ACTIVE STRIPE entitlement → REFUSED (FIX #2): the whole payment aborts;
     *     the operator must move the cluster off card first (cancels Stripe).
     *   - otherwise → charged: ledger row + entitlement extension.
     *
     * Period anchoring (per package):
     *   - active entitlement (expires in the future) → STACK: the new period
     *     starts at the current expiry and runs one more interval;
     *   - expired or no entitlement → ANCHOR AT NOW: the new period starts now.
     * The interval is the line's interval when given, else the existing
     * entitlement's, else monthly.
     *
     * Order per line: (1) compute the new expiry, (2) insert the ledger row,
     * (3) grant/extend the entitlement (deferring recompute) carrying the ledger
     * id in metadata, then backfill the entitlement id onto the ledger row.
     * After all lines: recompute access ONCE and reactivate a blocked tenant.
     *
     * @param  array<int, array{module: BillableModule, plan: BillingPackage, amount: int, interval?: string|null}>  $lines
     * @return Collection<int, SubscriptionPayment> the ledger rows created (one per charged module)
     *
     * @throws InvalidArgumentException on an invalid method, amount, interval,
     *                                  card cluster / active-stripe module, a
     *                                  duplicate, an empty line set, or when every
     *                                  line was excluded (nothing to charge)
     */
    public function recordManual(
        Tenant $tenant,
        array $lines,
        string $method,
        Carbon $paidAt,
        ?string $notes,
        User $recordedBy,
    ): Collection {
        // Manual recordings are out-of-band only (transfer/cash). `card` is
        // settled by Stripe and is never a manual payment (defends the service
        // even if a caller bypasses SubscriptionPaymentStoreRequest).
        if (! in_array($method, SubscriptionPayment::MANUAL_METHODS, true)) {
            throw new InvalidArgumentException("Invalid manual payment method: {$method}.");
        }

        if ($lines === []) {
            throw new InvalidArgumentException('El pago manual debe incluir al menos un módulo.');
        }

        foreach ($lines as $line) {
            if (! ($line['module'] ?? null) instanceof BillableModule) {
                throw new InvalidArgumentException('Cada línea del pago manual debe referir a un módulo válido.');
            }

            if (! ($line['plan'] ?? null) instanceof BillingPackage) {
                throw new InvalidArgumentException('Cada línea del pago manual debe referir a un plan válido.');
            }

            if ((int) ($line['amount'] ?? 0) < 1) {
                throw new InvalidArgumentException('El monto de cada módulo debe ser al menos 1 (minor units).');
            }
        }

        // A cluster on card is billed by Stripe: a manual payment for ANY module
        // would clobber its Stripe entitlement on grant. Refuse the whole payment.
        if ($tenant->payment_method === Tenant::PAYMENT_METHOD_CARD) {
            throw new InvalidArgumentException(
                'El condominio paga con tarjeta. Cambia el método de pago a transferencia/efectivo antes de registrar un pago manual.'
            );
        }

        return DB::transaction(function () use ($tenant, $lines, $method, $paidAt, $notes, $recordedBy): Collection {
            $payments = collect();

            foreach ($lines as $line) {
                $payment = $this->recordManualLine(
                    $tenant,
                    $line['module'],
                    $line['plan'],
                    (int) $line['amount'],
                    $method,
                    $paidAt,
                    $notes,
                    $recordedBy,
                    $line['interval'] ?? null,
                );

                if ($payment !== null) {
                    $payments->push($payment);
                }
            }

            if ($payments->isEmpty()) {
                throw new InvalidArgumentException(
                    'No hay módulos pagados que cobrar: todos los módulos seleccionados son de cortesía (gratuitos).'
                );
            }

            // Rebuild access ONCE after every grant (grant() deferred recompute).
            // recompute() is idempotent, so one pass yields the same access state
            // as one-per-grant but with a single projection rebuild.
            $this->entitlementService->recompute($tenant);

            // A manual payment reactivates a blocked cluster.
            if ($tenant->status === Tenant::STATUS_BLOQUEADO) {
                $tenant->update(['status' => Tenant::STATUS_ACTIVO]);
            }

            return $payments->map(fn (SubscriptionPayment $payment): SubscriptionPayment => $payment->fresh());
        });
    }

    /**
     * Record a single module line of a manual payment inside the caller's
     * transaction. Returns the ledger row, or NULL when the module is a courtesy
     * (active super_admin_free) entitlement that must be excluded from the charge.
     *
     * @throws InvalidArgumentException on an active-stripe module or a duplicate
     */
    private function recordManualLine(
        Tenant $tenant,
        BillableModule $module,
        BillingPackage $plan,
        int $amount,
        string $method,
        Carbon $paidAt,
        ?string $notes,
        User $recordedBy,
        ?string $interval,
    ): ?SubscriptionPayment {
        $now = now();

        $existing = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->first();

        $isActiveEntitlement = $existing !== null
            && ($existing->expires_at === null || $existing->expires_at->greaterThan($now));

        // Courtesy modules are $0 and perpetual — EXCLUDE them from the charge
        // (do not write a ledger row, do not touch the entitlement).
        if ($isActiveEntitlement && $existing->source === TenantEntitlement::SOURCE_SUPER_ADMIN_FREE) {
            return null;
        }

        // FIX #2 — never clobber a Stripe-billed module. The grant() upsert is
        // keyed by (tenant_id, billable_module_id), so recording a manual payment
        // on a module carrying an ACTIVE stripe entitlement would flip its source
        // to manual and null its stripe ids. Refuse: the operator must cancel
        // Stripe first (switching the cluster off card does this).
        if ($isActiveEntitlement && $existing->source === TenantEntitlement::SOURCE_STRIPE) {
            throw new InvalidArgumentException(
                "El módulo '{$module->key}' tiene una suscripción Stripe activa. Cancela la suscripción (o cambia el método de pago del condominio) antes de registrar un pago manual."
            );
        }

        // FIX #1 — idempotency guard against a double submit. A manual recording
        // is a low-frequency super-admin action, so a defensive in-transaction
        // check (no extra schema) is enough: an identical recent payment (same
        // tenant + module + amount + paid_at) is refused so the ledger is never
        // duplicated and the entitlement cycle is never stacked twice.
        $duplicate = SubscriptionPayment::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->where('amount', $amount)
            ->where('paid_at', $paidAt)
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException(
                "Ya existe un pago manual idéntico para el módulo '{$module->key}' (mismo monto y fecha de pago). No se registró de nuevo para evitar duplicarlo."
            );
        }

        $resolvedInterval = $this->resolveInterval($interval, $existing);

        // STACK on an unexpired entitlement, otherwise ANCHOR at now.
        $currentExpiry = $existing?->expires_at;
        $periodStart = ($currentExpiry !== null && $currentExpiry->greaterThan($now))
            ? $currentExpiry->copy()
            : $now->copy();

        $newExpiry = $resolvedInterval === PlanModulePrice::INTERVAL_YEAR
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();

        // (2) Ledger row first (entitlement id backfilled after the grant).
        $payment = SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'tenant_entitlement_id' => null,
            'amount' => $amount,
            'currency' => $tenant->currency ?: 'MXN',
            'method' => $method,
            'paid_at' => $paidAt,
            'period_start' => $periodStart,
            'period_end' => $newExpiry,
            'recorded_by_user_id' => $recordedBy->id,
            'notes' => $notes,
        ]);

        // (3) Extend/grant the entitlement with source=MANUAL (paid, not courtesy).
        // recompute is deferred to the end of recordManual (one rebuild for all).
        $entitlement = $this->entitlementService->grant(
            $tenant,
            $module,
            $plan,
            TenantEntitlement::SOURCE_MANUAL,
            [
                'interval' => $resolvedInterval,
                'expires_at' => $newExpiry,
                'metadata' => ['subscription_payment_id' => $payment->id],
            ],
            recompute: false,
        );

        $payment->update(['tenant_entitlement_id' => $entitlement->id]);

        return $payment;
    }

    /**
     * Switch a cluster's payment method, cancelling its Stripe subscription when
     * moving OFF card.
     *
     * The cancellation is a Stripe network call, so it runs OUTSIDE any DB
     * transaction. The tenant.payment_method write is audited (Tenant is
     * Auditable) by the model update itself.
     *
     * Owner resolution is the CALLER's job: this method cancels whatever active
     * $subscription it is handed. The controller refuses the switch when an active
     * subscription exists but the account owner is ambiguous (FIX #5), so this
     * method is never asked to "switch but skip cancel" while a charge is live.
     *
     * @throws InvalidArgumentException on an invalid method or cancel mode
     */
    public function changePaymentMethod(
        Tenant $tenant,
        string $method,
        ?Subscription $subscription = null,
        string $cancelMode = self::CANCEL_AT_PERIOD_END,
    ): void {
        if (! in_array($method, Tenant::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException("Invalid payment method: {$method}.");
        }

        if (! in_array($cancelMode, self::CANCEL_MODES, true)) {
            throw new InvalidArgumentException("Invalid cancel mode: {$cancelMode}.");
        }

        if ($method !== Tenant::PAYMENT_METHOD_CARD && $subscription !== null && $subscription->active()) {
            if ($cancelMode === self::CANCEL_NOW) {
                $subscription->cancelNow();
            } else {
                $subscription->cancel();
            }
        }

        $tenant->update(['payment_method' => $method]);
    }

    /**
     * Resolve the billing interval: explicit argument wins, else the existing
     * entitlement's interval, else monthly.
     */
    private function resolveInterval(?string $interval, ?TenantEntitlement $existing): string
    {
        $candidate = $interval ?? $existing?->interval ?? PlanModulePrice::INTERVAL_MONTH;

        if (! in_array($candidate, PlanModulePrice::INTERVALS, true)) {
            throw new InvalidArgumentException("Intervalo inválido: {$candidate}.");
        }

        return $candidate;
    }
}
