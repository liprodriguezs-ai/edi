<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Contracts;

/**
 * Counts the entities a tenant's billing band is measured against.
 *
 * Billing bands cap "how much" of the product a tenant may hold
 * (`billing_package.suggested_unit_max`). WHAT is counted is a domain decision,
 * not a billing one — the template counted housing units; EDI will count its own
 * subject (evaluated children, screenings per period, or health centers).
 *
 * Until that subject is defined, {@see NullQuotaSubjectCounter} returns 0, which
 * makes every band check pass. Bind a real implementation in
 * SubscriptionPlanServiceProvider and the guards start enforcing without any
 * change to UnitLimitService.
 */
interface QuotaSubjectCounter
{
    /**
     * The current tenant's live count of the billed subject.
     *
     * Implementations MUST be tenant-scoped (BelongsToTenant) and MUST exclude
     * soft-deleted rows, so the number matches the liveness rule the uniqueness
     * constraints of that entity use.
     */
    public function count(): int;
}
