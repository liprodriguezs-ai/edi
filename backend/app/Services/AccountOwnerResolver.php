<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Resolve a tenant's SINGLE account owner — the person whose Stripe
 * customer/card backs the subscription. Returns null when the owner is
 * missing or AMBIGUOUS (more than one `account.owner`), so callers fail safely
 * instead of operating on an arbitrary owner's subscription.
 *
 * Extracted from the IDENTICAL duplicate guards in
 * ConfigurationController::accountOwnerForTenant and
 * BillingCheckoutController::accountOwnerForTenant. Deliberately does NOT
 * cover SubscriptionPaymentController::accountOwnerForTenant, which uses
 * `$tenant->owners()` with DIFFERENT failure behavior (empty instead of
 * throwing when the role is unseeded) — pinned by
 * SubscriptionPaymentFlowTest::test_patch_payment_method_refuses_when_owner_is_ambiguous_and_subscription_active.
 *
 * Registered `$this->app->scoped(...)` in AppServiceProvider: one instance per
 * request so the memo below actually holds for the whole request without
 * pinning one tenant's owner across Octane-reused requests the way
 * `singleton()` would (precedent: CollectionsServiceProvider's
 * DelinquencyRestrictionService).
 */
final class AccountOwnerResolver
{
    /**
     * Keyed by tenant id, NOT a single flat cache — same reasoning as
     * DelinquencyRestrictionService::$settingsCache.
     *
     * @var array<string, User|false>
     */
    private array $memo = [];

    /**
     * Resolve THIS tenant's single account owner, or null when missing,
     * ambiguous, or the `account.owner` role catalog row itself is unseeded.
     *
     * Spatie's `role()` scope throws RoleDoesNotExist when the role does not
     * exist as a CATALOG row at all (not merely "zero users have it") —
     * distinct from a missing/ambiguous owner. This resolver is now called
     * from SubscriptionStateService::narrativeFor() on every entitled/blocked
     * tenant read, a far broader surface than the two config/checkout screens
     * it was extracted from, so an unseeded platform catalog (fresh install,
     * a test not seeding roles) must degrade to "no owner" — never a 500.
     */
    public function resolve(Tenant $tenant): ?User
    {
        if (! array_key_exists($tenant->id, $this->memo)) {
            try {
                $owners = $tenant->users()->role(Role::SLUG_ACCOUNT_OWNER)->get();
            } catch (RoleDoesNotExist) {
                $owners = collect();
            }

            $this->memo[$tenant->id] = $owners->count() === 1 ? $owners->first() : false;
        }

        $owner = $this->memo[$tenant->id];

        return $owner === false ? null : $owner;
    }
}
