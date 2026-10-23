<?php

namespace Modules\SubscriptionPlan\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\BillingNotificationService;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\Tenant\Models\Tenant;

/**
 * billing:expire-manual-entitlements — daily cron (WU4).
 *
 * Enforces the lapse for every NON-STRIPE grant, whose expiry has no webhook to
 * police it:
 *   - trial            (a LOCAL, card-free trial — either the super-admin's
 *                       no-card courtesy alta, TenantProvisioningService::MODE_TRIAL,
 *                       or a per-module add-on trial, EntitlementService::
 *                       startModuleTrial. Self-registration no longer grants a
 *                       local trial: it is born blocked and the trial the owner
 *                       gets is a Stripe Checkout trial, source=stripe, policed
 *                       by the webhook below, not this cron.)
 *   - manual           (paid out-of-band by transfer/cash, one cycle per payment)
 *   - super_admin_free (courtesy grant)
 *
 * Stripe-sourced entitlements are DELIBERATELY excluded from this trigger — a card
 * subscription that fails renewal flips to past_due and the Stripe webhook expires
 * the row + recomputes. This cron NEVER expires or recomputes stripe rows (the
 * webhook owns them) and never blocks a card organization.
 *
 * The ONE thing it does for Stripe is a status-only reconciliation pass
 * (reconcileOrphanStripeStatuses): a lapsed stripe entitlement with no remaining
 * coverage moves tenant.status off the stale 'activo' to 'pendiente_pago'. No
 * entitlement is written, no access is recomputed. See that method for why.
 *
 * The gap this covers (generalized from the old transfer/cash-only version): a
 * local (card-free) trial has NO webhook of its own — nobody else would recompute
 * it, so module_tenant.enabled / system_tenant would stay projected as granted
 * after it lapsed. It is caught here (trial in the trigger set, no
 * payment_method filter).
 *
 * For each candidate (a tenant with an expired non-stripe entitlement), access is
 * ALWAYS recomputed: recompute() reads only the still-active rows (scopeActive), so
 * the lapsed entitlement's granted modules turn OFF — EVEN WHEN another entitlement
 * keeps the organization up. This closes the partial-lapse gap: an addon trial expiring
 * while the base stays active must switch the addon's module_tenant.enabled to false
 * (it stayed stale=true before, because the old code skipped recompute whenever any
 * entitlement was still active). recompute() never mutates entitlement rows, so
 * active stripe grants are untouched, and it is idempotent.
 *
 * The organization is BLOCKED (STATUS_BLOQUEADO) only when NO entitlement of ANY source
 * remains active — a still-active stripe/manual/other grant keeps it up. This aligns
 * with EntitlementService::recompute, which reads every active row regardless of
 * source.
 *
 * recompute() targets an EXPLICIT tenant (central connection, no ambient
 * TenantScope), so no tenancy initialization is needed here. now() is read per
 * invocation, keeping the command Octane-safe.
 */
class ExpireManualEntitlementsCommand extends Command
{
    protected $signature = 'billing:expire-manual-entitlements';

    protected $description = 'Daily: block organizations whose non-stripe entitlement (trial, manual or super_admin_free) has expired with no remaining active coverage, and recompute their access. Stripe is policed by its webhook.';

    /**
     * Non-stripe sources this cron polices. Stripe is excluded — its lifecycle is
     * driven by the Cashier webhook, not this out-of-band sweep.
     *
     * @var array<int, string>
     */
    private const NON_STRIPE_SOURCES = [
        TenantEntitlement::SOURCE_TRIAL,
        TenantEntitlement::SOURCE_MANUAL,
        TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
    ];

    public function handle(EntitlementService $entitlementService, BillingNotificationService $billingNotificationService): int
    {
        $now = now();

        // Only tenants carrying an EXPIRED non-stripe entitlement are candidates.
        // A lone expired stripe row is NOT a trigger (webhook territory). Scoping
        // by tenant id keeps the sweep off organizations that never had such a grant.
        // #4 — get() (not chunk/cursor) is fine at the current organization count;
        // revisit with chunkById() if the candidate set grows large.
        $candidateTenantIds = TenantEntitlement::query()
            ->whereIn('source', self::NON_STRIPE_SOURCES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->distinct()
            ->pluck('tenant_id');

        $tenants = $candidateTenantIds->isEmpty()
            ? collect()
            : Tenant::query()->whereIn('id', $candidateTenantIds->all())->get();

        foreach ($tenants as $tenant) {
            $label = $tenant->name ?? $tenant->id;

            // ALWAYS recompute — the fix for the PARTIAL-lapse bug. A tenant may hold
            // entitlements on several packages from DIFFERENT sources: when an ADDON
            // trial lapses while the BASE stays active, recompute() (which reads only
            // scopeActive rows) turns the lapsed addon's module_tenant.enabled OFF and
            // leaves the base modules ON. The old code did `continue` before recompute
            // whenever any entitlement was still active, so the vencido module stayed
            // enabled=true (stale) and its API kept answering. recompute() never mutates
            // entitlement rows, so active stripe grants are untouched, and it is
            // idempotent (rerunning yields the same access state).
            $entitlementService->recompute($tenant);

            // Notify the organization administrator(s) ONCE per expired SOURCE_TRIAL
            // entitlement. Expired trial rows are kept by design (never deleted), so
            // without a marker the daily sweep re-selected the same rows and re-sent
            // "tu prueba de {módulo} venció" every run, forever. trial_expiry_notified_at
            // gates the select (whereNull) and is stamped after processing, so each
            // expired trial notifies at most once. Runs inside $tenant->run() so the
            // tenant context is available for in-app row creation and role resolution;
            // DB::afterCommit inside notifyEvent fires immediately here (no open
            // transaction), so notifications are delivered synchronously.
            $expiredTrials = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('source', TenantEntitlement::SOURCE_TRIAL)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->whereNull('trial_expiry_notified_at')
                ->with('billable_module')
                ->get();

            foreach ($expiredTrials as $expiredTrial) {
                /** @var BillableModule|null $billableModule */
                $billableModule = $expiredTrial->billable_module;

                if ($billableModule === null) {
                    continue;
                }

                // Skip the notification if this billable module is currently kept active
                // by an entitlement (e.g. it was paid / re-granted since the trial
                // lapsed) — telling a paying admin their access lapsed would be false.
                // Under the unique(tenant_id, billable_module_id) constraint the trial
                // row is the only row for this module, and grant() upserts in place
                // (flipping source off 'trial'), so a paid module is already excluded by
                // the source filter above; this guard is belt-and-suspenders should that
                // one-row-per-module model ever change (e.g. re-triggerable trials).
                $moduleStillActive = TenantEntitlement::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('billable_module_id', $expiredTrial->billable_module_id)
                    ->active()
                    ->exists();

                if (! $moduleStillActive) {
                    $tenant->run(function () use ($billingNotificationService, $billableModule): void {
                        $billingNotificationService->notifyTrialExpired($billableModule);
                    });
                }

                // Mark notified either way, so a lapsed-then-paid module never re-fires
                // (the marker survives grant()'s upsert — it is not in the update set).
                $expiredTrial->forceFill(['trial_expiry_notified_at' => $now])->save();
            }

            // Block the organization ONLY when NO entitlement of ANY source remains active
            // (none with a null or still-future expiry). A still-active
            // stripe/manual/other grant keeps the organization up — card organizations WITH a
            // live Stripe subscription are Stripe's job. Checked AFTER recompute; since
            // recompute() does not touch entitlement rows, the result is the same
            // either way — the ordering just keeps "turn off stale modules, then decide
            // blocking" readable.
            $hasAnyActiveEntitlement = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->active()
                ->exists();

            if ($hasAnyActiveEntitlement) {
                $this->info("[{$label}] Entitlement no-stripe vencido: módulos del entitlement vencido apagados; la organización sigue activo por otra cobertura.");

                continue;
            }

            if ($tenant->status !== Tenant::STATUS_BLOQUEADO) {
                $tenant->update(['status' => Tenant::STATUS_BLOQUEADO]);
            }

            $this->info("[{$label}] Entitlement no-stripe vencido: organización bloqueado y accesos recalculados.");
        }

        $this->reconcileOrphanStripeStatuses($now);

        $this->info('billing:expire-manual-entitlements completed.');

        return self::SUCCESS;
    }

    /**
     * STATUS-ONLY reconciliation for a lapsed STRIPE entitlement (F-27).
     *
     * A card subscription can lapse with no `customer.subscription.deleted` webhook
     * — Stripe ends dunning in `unpaid` without cancelling, or the event is lost.
     * The API already denies access (it is computed live off the entitlement), but
     * tenant.status stayed 'activo' forever, so every super-admin panel and report
     * read a lie.
     *
     * Deliberately NARROW, because this is the one path that touches card organizations:
     *   - Only tenants with NO active entitlement of ANY source are candidates — a
     *     live stripe/manual/courtesy grant is never touched (invariant).
     *   - Status moves to PENDIENTE_PAGO, never BLOQUEADO: Stripe may still recover
     *     the invoice, and blocking is `subscription.deleted`'s call.
     *   - recompute() is NOT called. It purges the organization's module Settings, which
     *     is irreversible; doing that on a recoverable dunning window would destroy
     *     real configuration for a customer who is about to pay. The stale
     *     module_tenant rows grant nothing on their own (EnsureSubscriptionActive
     *     blocks the whole API while no entitlement is active) and the recovery
     *     webhook (invoice.payment_succeeded) recomputes them anyway.
     *
     * tenant.status is informational only — no access decision reads it — so this
     * can never cut service to anybody.
     */
    private function reconcileOrphanStripeStatuses(Carbon $now): void
    {
        $lapsedStripeTenantIds = TenantEntitlement::query()
            ->where('source', TenantEntitlement::SOURCE_STRIPE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->distinct()
            ->pluck('tenant_id');

        if ($lapsedStripeTenantIds->isEmpty()) {
            return;
        }

        $tenants = Tenant::query()
            ->whereIn('id', $lapsedStripeTenantIds->all())
            ->where('status', Tenant::STATUS_ACTIVO)
            ->get();

        foreach ($tenants as $tenant) {
            $hasAnyActiveEntitlement = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->active()
                ->exists();

            if ($hasAnyActiveEntitlement) {
                continue;
            }

            $tenant->update(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

            $this->info(sprintf(
                '[%s] Entitlement de Stripe vencido sin webhook: estado reconciliado a pendiente_pago (accesos intactos).',
                $tenant->name ?? $tenant->id,
            ));
        }
    }
}
