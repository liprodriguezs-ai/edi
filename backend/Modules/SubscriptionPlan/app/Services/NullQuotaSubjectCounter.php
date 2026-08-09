<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Services;

use Modules\SubscriptionPlan\Contracts\QuotaSubjectCounter;

/**
 * Placeholder counter used while EDI has no billed subject yet.
 *
 * Returning 0 makes every band check fail-open: no tenant is ever blocked by a
 * quota that has nothing to measure. The billing machinery (bands, downgrade
 * guard, suggested plan) stays wired and tested — only the number is missing.
 *
 * Replace the binding in SubscriptionPlanServiceProvider once the subject
 * exists; do NOT re-introduce a direct model dependency inside UnitLimitService.
 */
class NullQuotaSubjectCounter implements QuotaSubjectCounter
{
    public function count(): int
    {
        return 0;
    }
}
