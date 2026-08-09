<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Services;

/**
 * Result DTO shared by TWO distinct guards on UnitLimitService, each reading
 * these fields under DIFFERENT semantics:
 *
 * 1. checkIncrease() — INCREASE semantics (original). `planName`/`unitLimit`
 *    describe the tenant's CURRENT band (from resolveBand()).
 *    `attemptedIncrease` is the size of the delta being attempted.
 *    `suggestedPlanName` excludes the current band — it is always a
 *    DIFFERENT (upgrade) plan, or null at the top band.
 *
 * 2. checkPlanFits() — TARGET semantics (new, plan-downgrade-guard).
 *    `planName`/`unitLimit` describe the TARGET plan being evaluated, never
 *    `resolveBand()`'s current band. `attemptedIncrease` is always 0 — it is
 *    meaningless for a plan-fit check (nothing is being added), which is why
 *    `UnitLimitExceededException::forPlanChange()`'s payload omits it entirely
 *    rather than surface a misleading 0. `suggestedPlanName` here does NOT
 *    exclude the current plan: the tenant's own current plan legitimately IS
 *    the smallest fitting band when the target under-serves them, and
 *    surfacing "the plan that fits is the one you already have" is the
 *    correct, informative answer — not a dead-end.
 *
 * `planName`/`unitLimit` are null when the guard is fail-open. Under INCREASE
 * semantics this happens when there is no active entitlement, or the
 * resolved billing package has no `suggested_unit_max` (see
 * UnitLimitService::resolveBand()). Under TARGET semantics this instead
 * happens ONLY when tenancy is not initialized (mirrors checkIncrease's
 * fail-open) — otherwise `planName`/`unitLimit` are read directly off the
 * TARGET `BillingPackage` argument, never through resolveBand().
 */
final class UnitLimitCheck
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $planName,
        public readonly ?int $unitLimit,
        public readonly int $activeUnits,
        public readonly int $attemptedIncrease,
        public readonly ?string $suggestedPlanName,
    ) {}
}
