<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\CalendarDays;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Laravel\Cashier\Subscription as CashierSubscription;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\StripeSubscriptionStatuses;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Stripe\Subscription as StripeApiSubscription;

// The active-tenant resolution used to live here, but it IGNORED X-Tenant-Id and
// so diverged from InitializeTenancyFromUser — the root cause of the enforcement
// bypass. Callers now resolve the request's tenant via App\Services\ActiveTenantResolver
// (the single shared resolver) and pass it into gateFor(), so the tenant that is
// enforced is always the tenant the request operates on.

/**
 * Single source of truth for a cluster's access state, split into a CHEAP gate
 * (hot path, `tenant_entitlement` only) and a rich narrative (allowlisted
 * routes only, gate + at most one Cashier subscription read).
 *
 * TWO entry points, preserved deliberately (not collapsed into one):
 *   - gateFor(?User, ?Tenant): SubscriptionGate — ACTOR-aware: a platform role
 *     (super admin / lawyer) is exempt, short-circuited before any tenant
 *     read. Sole consumer: EnsureSubscriptionActive.
 *   - narrativeFor(User, ?Tenant): array — TENANT-scoped, NO role
 *     short-circuit (successor of the old forTenant()). Consumers:
 *     AuthController and TenantProvisioningController — the latter passes the
 *     super-admin actor precisely to DESCRIBE the new cluster, so an
 *     actor-aware narrative would wrongly report 'exempt' for it.
 *
 * Both share ONE private computation — tenantGate() — for `is_blocked`, so the
 * two paths can never disagree on that value by construction of the call
 * graph. What they CANNOT guarantee structurally is that narrativeFor()'s
 * returned array keeps echoing that exact value forever (a future edit could
 * replace the 'is_blocked' entry) — see SubscriptionStateCompositionTest, the
 * NAMED enforcement mechanism for that invariant.
 *
 * Trial truth is read from Cashier's synced `stripe_status` (a Stripe Checkout
 * trial) or from the entitlement's own `source=trial` (a local courtesy
 * trial) — never from a local metadata mirror. The `stripe_trialing` marker
 * this service used to read stuck 'trial' forever on a perpetual (null-expiry)
 * entitlement once billing.trial_days changed underneath it; Cashier's own
 * status is the actual source of truth and cannot drift.
 */
class SubscriptionStateService
{
    public function __construct(private readonly AccountOwnerResolver $ownerResolver) {}

    /**
     * CHEAP access decision — the sole input of EnsureSubscriptionActive. Reads
     * `tenant_entitlement` ONLY: never Cashier's `subscriptions` table, never
     * resolves the account owner. Its query count must never exceed today's for
     * the same request (spec: subscription-state-derivation).
     */
    public function gateFor(?User $user, ?Tenant $tenant = null): SubscriptionGate
    {
        if ($user === null) {
            return new SubscriptionGate(false, 'none', false, collect());
        }

        if ($user->hasRole([Role::SLUG_SUPER_ADMIN, Role::SLUG_LAWYER])) {
            return new SubscriptionGate(false, 'exempt', false, collect());
        }

        return $this->tenantGate($user, $tenant);
    }

    /**
     * Rich, TENANT-scoped access state (successor of forTenant()). NO role
     * short-circuit — see class docblock. Composes the SAME gate computation
     * gateFor() uses (never re-derives `is_blocked` independently) plus at
     * most one Cashier subscription read.
     *
     * @return array<string, mixed>
     */
    public function narrativeFor(User $user, ?Tenant $tenant): array
    {
        $gate = $this->tenantGate($user, $tenant);

        return match ($gate->status) {
            'none' => [
                'status' => 'none',
                'is_blocked' => $gate->isBlocked,
                'is_owner' => $gate->isOwner,
                'payment_issue' => false,
            ],
            'entitled' => $this->entitledNarrative($gate, $tenant),
            default => $this->blockedNarrative($gate, $tenant),
        };
    }

    /**
     * The SINGLE `is_blocked` computation shared by gateFor() and
     * narrativeFor(). Gate vocabulary deliberately excludes trial/active
     * (narrative refines entitled → active|trial, blocked → expired|none) —
     * those refinements need a second (Cashier) or third (stripe-history
     * exists) query this method must never pay for.
     */
    private function tenantGate(?User $user, ?Tenant $tenant): SubscriptionGate
    {
        if ($user === null || ! $tenant) {
            return new SubscriptionGate(false, 'none', false, collect());
        }

        $isOwner = $user->hasRole(Role::SLUG_ACCOUNT_OWNER);
        $activeEntitlements = $this->activeEntitlementsFor($tenant);

        if ($activeEntitlements->isNotEmpty()) {
            return new SubscriptionGate(false, 'entitled', $isOwner, $activeEntitlements);
        }

        return new SubscriptionGate(true, 'blocked', $isOwner, $activeEntitlements);
    }

    /**
     * Canonical path: any ACTIVE entitlement (trial, stripe, manual,
     * super_admin_free, legacy_plan) grants access. Shared by tenantGate()
     * and quotaScopeFor() so both read the SAME set of rows.
     *
     * @return Collection<int, TenantEntitlement>
     */
    private function activeEntitlementsFor(Tenant $tenant): Collection
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->get();
    }

    /**
     * Cheap, write-path scope resolution for QuotaService (trial-usage-quotas
     * REQ-QE-02): 'trial' or 'active', following the SAME authoritative
     * precedence entitledNarrative() uses via the shared isTrialScope()
     * predicate — the quota guard MUST NOT re-derive the trial signal
     * independently. Deliberately does NOT call narrativeFor() (full payload,
     * wasted cost on a hot write path — design D1): one entitlement query +
     * the owner's memoized subscription read, only when at least one
     * `plan_quota` row for the resource is finite (QuotaService checks that
     * FIRST).
     */
    public function quotaScopeFor(Tenant $tenant): string
    {
        $activeEntitlements = $this->activeEntitlementsFor($tenant);
        $gate = new SubscriptionGate(
            false,
            $activeEntitlements->isNotEmpty() ? 'entitled' : 'blocked',
            false,
            $activeEntitlements,
        );

        $isTrial = $this->isTrialScope($gate, $this->ownerSubscription($tenant));

        return $isTrial ? PlanQuota::SCOPE_TRIAL : PlanQuota::SCOPE_ACTIVE;
    }

    /**
     * The EXACT trial-precedence rule from entitledNarrative(), extracted so
     * quotaScopeFor() can reuse it without re-deriving the trial signal
     * (REQ-QE-02). $subscription is passed in already-resolved — never
     * re-queried here — so this predicate costs zero additional queries
     * beyond what its caller already paid via ownerSubscription().
     */
    private function isTrialScope(SubscriptionGate $gate, ?CashierSubscription $subscription): bool
    {
        if ($subscription !== null && $subscription->stripe_status === StripeApiSubscription::STATUS_TRIALING) {
            return true;
        }

        $paid = $gate->activeEntitlements->reject(
            fn (TenantEntitlement $entitlement): bool => $entitlement->source === TenantEntitlement::SOURCE_TRIAL
        );

        return $paid->isEmpty();
    }

    /**
     * Refine 'entitled' into active|trial + payment_issue, reading the owner's
     * Cashier subscription at most once.
     *
     * Precedence: a LIVE Stripe trial (`stripe_status='trialing'`) always wins
     * — its date is Stripe's, not the entitlement's. Otherwise, if every
     * active entitlement is a LOCAL courtesy trial (`source=trial`, no plan/
     * card behind it), report the courtesy trial from the entitlement's own
     * expiry. Anything else — including a converted Stripe subscription with a
     * perpetual (null) entitlement expiry, and a stripe entitlement with an
     * unresolved owner/no Cashier row — reports 'active'.
     *
     * @return array<string, mixed>
     */
    private function entitledNarrative(SubscriptionGate $gate, Tenant $tenant): array
    {
        $subscription = $this->ownerSubscription($tenant);

        if (! $this->isTrialScope($gate, $subscription)) {
            // "Paid-like" = every active entitlement that is NOT a local
            // courtesy trial. isTrialScope() already ruled out both the
            // Stripe-trialing case and the all-local-trial case above.
            $paid = $gate->activeEntitlements->reject(
                fn (TenantEntitlement $entitlement): bool => $entitlement->source === TenantEntitlement::SOURCE_TRIAL
            );

            return [
                'status' => 'active',
                'is_blocked' => false,
                'is_owner' => $gate->isOwner,
                'expires_at' => $this->furthestExpiry($paid),
                'payment_issue' => $this->entitledPaymentIssue($tenant, $subscription),
            ];
        }

        if ($subscription !== null && $subscription->stripe_status === StripeApiSubscription::STATUS_TRIALING) {
            // Post-Phase-8 cast: trial_ends_at is already an app-timezone Carbon.
            $trialEnd = $subscription->trial_ends_at;
            $trialEndsAt = optional($trialEnd)->toIso8601String();

            return [
                'status' => 'trial',
                'is_blocked' => false,
                'is_owner' => $gate->isOwner,
                'trial_ends_at' => $trialEndsAt,
                'expires_at' => $trialEndsAt,
                'trial_days_left' => CalendarDays::until($trialEnd),
                // A Stripe trial already has a plan and a card behind it and
                // auto-charges when it ends — the UI must NOT tell this owner
                // to "contrata un plan".
                'trial_auto_charges' => true,
                'payment_issue' => $this->entitledPaymentIssue($tenant, $subscription),
            ];
        }

        // A LOCAL trial has neither a plan nor a card — the owner genuinely
        // must contract one or lose access.
        $trialExpiryCarbon = $this->furthestExpiryCarbon($gate->activeEntitlements);
        $trialExpiry = optional($trialExpiryCarbon)->toIso8601String();

        return [
            'status' => 'trial',
            'is_blocked' => false,
            'is_owner' => $gate->isOwner,
            'trial_ends_at' => $trialExpiry,
            'expires_at' => $trialExpiry,
            'trial_days_left' => CalendarDays::until($trialExpiryCarbon),
            'trial_auto_charges' => false,
            'payment_issue' => $this->entitledPaymentIssue($tenant, $subscription),
        ];
    }

    /**
     * ENTITLED-branch payment_issue: today's unconditional tenant-status check
     * is KEPT (zero customer-visible change on a paywall refactor) — a Stripe
     * dunning status is an ADDITIONAL true-condition, never a replacement.
     * `handleInvoicePaymentFailed` sets PENDIENTE_PAGO without writing any
     * subscription status, and Stripe's retry dunning can leave the
     * subscription `active` for a whole retry window — dropping the tenant-
     * status check would blank the "update your card" warning for days.
     */
    private function entitledPaymentIssue(Tenant $tenant, ?CashierSubscription $subscription): bool
    {
        return $tenant->status === Tenant::STATUS_PENDIENTE_PAGO
            || ($subscription !== null && in_array($subscription->stripe_status, StripeSubscriptionStatuses::DUNNING, true));
    }

    /**
     * Refine 'blocked' into expired|none + payment_issue.
     *
     * @return array<string, mixed>
     */
    private function blockedNarrative(SubscriptionGate $gate, Tenant $tenant): array
    {
        // Distinguish a cluster whose entitlements all EXPIRED (e.g. a lapsed
        // trial) from one that never had any.
        $everHadEntitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->exists();

        $subscription = $this->ownerSubscription($tenant);

        // D8 — hard-blocked is NOT always the same story: a cluster that never
        // chose a plan, one whose subscription was CANCELLED (webhook sets
        // BLOQUEADO), and one whose day-31 auto-charge FAILED (webhook leaves
        // it PENDIENTE_PAGO, entitlement simply expires at trial_end with no
        // extension) all land here with is_blocked=true, but only the last one
        // has a card problem the owner can fix by updating it.
        //
        // "No live payment signal" ≡ stripe_status ∉ LIVE ∪ DUNNING — one rule
        // covering canceled, incomplete, incomplete_expired, paused, AND the
        // absent row, so a Stripe-canceled cluster still hits the fallback
        // exactly like one with no Cashier row at all.
        $paymentIssue = ($subscription !== null && in_array($subscription->stripe_status, StripeSubscriptionStatuses::DUNNING, true))
            || (! StripeSubscriptionStatuses::providesPaymentSignal($subscription?->stripe_status)
                && $tenant->status === Tenant::STATUS_PENDIENTE_PAGO
                && $this->hasStripeHistory($tenant));

        return [
            'status' => $everHadEntitlement ? 'expired' : 'none',
            'is_blocked' => $gate->isBlocked,
            'is_owner' => $gate->isOwner,
            'payment_issue' => $paymentIssue,
        ];
    }

    /**
     * Whether the cluster EVER held a Stripe-sourced entitlement (active or
     * not) — the signal that a blocked cluster with no live Stripe payment
     * signal genuinely had a card behind it once (D8 guard), as opposed to one
     * that never chose a plan at all.
     */
    private function hasStripeHistory(Tenant $tenant): bool
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', TenantEntitlement::SOURCE_STRIPE)
            ->exists();
    }

    /**
     * The tenant's account owner's `default` Cashier subscription, or null
     * when the owner is unresolved (missing/ambiguous) or has none.
     *
     * Deliberately queries via subscriptions() (the relation METHOD) rather
     * than Cashier's own subscription() helper, which reads the ->subscriptions
     * property accessor: that accessor caches on the User model instance, and
     * AccountOwnerResolver memoizes that SAME instance per tenant for the rest
     * of the request — a second narrative resolution later in the same request
     * (e.g. right after a webhook/checkout mutates the row) must never serve a
     * stale cached relation.
     */
    private function ownerSubscription(Tenant $tenant): ?CashierSubscription
    {
        $owner = $this->ownerResolver->resolve($tenant);

        return $owner?->subscriptions()->where('type', 'default')->first();
    }

    /**
     * ISO-8601 of the LATEST expiry in the set, or null when any entitlement is
     * perpetual (null expires_at = never expires → access has no end date).
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     */
    private function furthestExpiry(Collection $entitlements): ?string
    {
        return optional($this->furthestExpiryCarbon($entitlements))->toIso8601String();
    }

    /**
     * Carbon-returning core of furthestExpiry() — the LATEST expiry in the
     * set, or null when any entitlement is perpetual. Exposed separately so
     * CalendarDays::until() can consume the Carbon directly, from the SAME
     * computation that produces the ISO string (never a second source).
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     */
    private function furthestExpiryCarbon(Collection $entitlements): ?CarbonInterface
    {
        if ($entitlements->contains(fn (TenantEntitlement $entitlement): bool => $entitlement->expires_at === null)) {
            return null;
        }

        $latest = $entitlements->sortByDesc('expires_at')->first();

        return $latest?->expires_at;
    }
}
