<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Services;

/**
 * Result DTO for QuotaService::checkConsumption()/assertCanRegisterUnits().
 * `scope` is null exactly when the guard is fail-open (no tenancy
 * initialized) — no numbers are resolved in that case. `limit` is null for
 * unlimited (D6 — the DTO NEVER carries the raw `-1` sentinel; that
 * translation happens once, inside QuotaService::resolveQuota()).
 */
final class QuotaCheck
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $resource,
        public readonly ?string $scope,
        public readonly ?int $limit,
        public readonly int $used,
        public readonly int $attempted,
        public readonly ?string $planName,
    ) {}
}
