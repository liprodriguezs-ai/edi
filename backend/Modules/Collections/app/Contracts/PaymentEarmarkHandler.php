<?php

namespace Modules\Collections\Contracts;

use Modules\Collections\Models\Payment;

/**
 * PaymentEarmarkHandler — contract for module-specific earmarked payments.
 *
 * An earmarked payment carries a directive: "when this payment is confirmed,
 * create/find the charge that belongs to MY entity and apply the full amount
 * pinned to it — never FIFO toward unrelated debt". Modules register their
 * handler in the PaymentEarmarkRegistry (keyed by earmark_type) from their
 * service provider; Collections never imports the consuming module.
 *
 * TENANCY CONTRACT: earmark_id has NO exists-rule at the controller — existence
 * AND tenant scoping are 100% the handler's job. Handlers MUST resolve the
 * earmarked entity through tenant-scoped Eloquent models (BelongsToTenant);
 * raw DB:: lookups or withoutGlobalScope(TenantScope) are forbidden — an
 * unscoped lookup would be a silent cross-tenant earmark vector.
 */
interface PaymentEarmarkHandler
{
    /**
     * Validate an earmarked payment at capture time (register/submit), BEFORE
     * the payment transaction starts.
     *
     * @param  array<string, mixed>  $data  The payment payload (unit_id, total_amount,
     *                                      earmark_type, earmark_id, ...).
     * @param  bool  $isSelfSubmission  True when the payer is the resident themself,
     *                                  reporting their own payment via `payment/submit`.
     *                                  False for admin-initiated `payment/register`
     *                                  (default) — a handler may restrict what a
     *                                  resident can self-report without restricting
     *                                  what an admin/treasurer can capture directly.
     *
     * @throws \InvalidArgumentException When the earmarked target cannot accept the payment.
     */
    public function validateSubmission(array $data, bool $isSelfSubmission = false): void;

    /**
     * Resolve the pinned applications for a CONFIRMED earmarked payment. Runs INSIDE
     * the confirmation transaction (the payment row is locked): create/find the
     * destination charge and return the manual applications that pin the money to it.
     *
     * @return array<int, array{charge_id: string, apply_amount: int}>
     *
     * @throws \InvalidArgumentException When the earmarked target can no longer be funded
     *                                   (rolls back the confirmation; the payment stays pending).
     */
    public function resolveApplications(Payment $payment): array;
}
