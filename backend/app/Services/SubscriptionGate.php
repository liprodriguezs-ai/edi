<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Modules\SubscriptionPlan\Models\TenantEntitlement;

/**
 * The CHEAP access decision: exactly what EnsureSubscriptionActive needs to
 * turn a organization's state into a 403 boundary, computed from `tenant_entitlement`
 * alone — never Cashier's `subscriptions` table, never an owner lookup.
 *
 * `final readonly`: the gate itself cannot be mutated after construction. This
 * does NOT, on its own, guarantee that a narrative built from this gate keeps
 * agreeing with it forever — see SubscriptionStateCompositionTest, the named
 * enforcement mechanism for that invariant (SubscriptionStateService docblock).
 */
final readonly class SubscriptionGate
{
    public function __construct(
        public bool $isBlocked,
        /** @var string none|exempt|blocked|entitled */
        public string $status,
        public bool $isOwner,
        /** @var Collection<int, TenantEntitlement> loaded once here; narrative reuses it */
        public Collection $activeEntitlements,
    ) {}
}
