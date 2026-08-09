<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Services;

use App\Services\SubscriptionStateService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionPlan\Exceptions\QuotaExceededException;
use Modules\SubscriptionPlan\Exceptions\UnitLimitExceededException;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Models\TenantQuotaUsage;
use Modules\Tenant\Models\Tenant;
use RuntimeException;

/**
 * Guards a tenant's trial/active USAGE against `plan_quota` (design D6: `-1`
 * or an absent row both mean unlimited, never leaked past resolveQuota()).
 * Cloned shape from UnitLimitService: check-, assert-, build-Message methods,
 * and the SAME fail-open criterion — misconfiguration must never brick daily
 * operations (REQ-QE-04).
 *
 * Two resource families:
 * - ACCUMULATING (`amenity_reservation`, `visitor`): lifetime counter in
 *   `tenant_quota_usage`, incremented by consume() inside the caller's
 *   transaction (D3). Never decremented (REQ-CT-03).
 * - INVENTORY (`unit`): no counter row — live count via
 *   UnitLimitService::activeUnitCount() (D5), composed with the existing
 *   billing-band guard in assertCanRegisterUnits().
 */
class QuotaService
{
    public function __construct(
        private readonly SubscriptionStateService $subscriptionStateService,
        private readonly UnitLimitService $unitLimitService,
    ) {}

    /**
     * The SINGLE translation point (D6) from the catalog's raw `limit_value`
     * to null-unlimited: `-1` or an absent row both become `null`. Picks the
     * most-specific `plan_quota` row — the tenant's active `billing_package_id`
     * beats the `billing_package_id IS NULL` global row (REQ-QC-02) — using
     * the SAME entitlement-query shape as UnitLimitService::resolveBand().
     */
    public function resolveQuota(string $resource, string $scope): ?int
    {
        $billingPackageId = TenantEntitlement::query()
            ->where('tenant_id', tenant('id'))
            ->active()
            ->with('billing_package')
            ->get()
            ->map(fn (TenantEntitlement $entitlement): ?string => $entitlement->billing_package?->id)
            ->filter()
            ->first();

        $row = null;

        if ($billingPackageId !== null) {
            $row = PlanQuota::query()
                ->where('billing_package_id', $billingPackageId)
                ->where('scope', $scope)
                ->where('resource_key', $resource)
                ->first();
        }

        if ($row === null) {
            $row = PlanQuota::query()
                ->whereNull('billing_package_id')
                ->where('scope', $scope)
                ->where('resource_key', $resource)
                ->first();
        }

        if ($row === null || $row->limit_value === PlanQuota::UNLIMITED) {
            return null;
        }

        return $row->limit_value;
    }

    /**
     * Whether consuming $delta more of $resource is allowed. Fail-open
     * (`allowed: true, scope: null`, no numbers resolved) when tenancy is not
     * initialized (REQ-QE-04). Cost control (design D1): resolves BOTH scopes'
     * `plan_quota` rows FIRST; only when NEITHER is finite does it allow
     * WITHOUT resolving the trial/active scope (the shipped-OFF fast path,
     * ~2 catalog queries, zero Cashier reads).
     */
    public function checkConsumption(string $resource, int $delta = 1): QuotaCheck
    {
        if (! tenancy()->initialized) {
            return new QuotaCheck(
                allowed: true,
                resource: $resource,
                scope: null,
                limit: null,
                used: 0,
                attempted: $delta,
                planName: null,
            );
        }

        $trialLimit = $this->resolveQuota($resource, PlanQuota::SCOPE_TRIAL);
        $activeLimit = $this->resolveQuota($resource, PlanQuota::SCOPE_ACTIVE);

        if ($trialLimit === null && $activeLimit === null) {
            return new QuotaCheck(
                allowed: true,
                resource: $resource,
                scope: null,
                limit: null,
                used: 0,
                attempted: $delta,
                planName: null,
            );
        }

        $tenant = Tenant::findOrFail(tenant('id'));
        $scope = $this->subscriptionStateService->quotaScopeFor($tenant);
        $limit = $scope === PlanQuota::SCOPE_TRIAL ? $trialLimit : $activeLimit;

        $used = (int) (TenantQuotaUsage::where('resource_key', $resource)->value('used_count') ?? 0);
        $planName = $this->resolvePlanName();

        if ($limit === null) {
            return new QuotaCheck(
                allowed: true,
                resource: $resource,
                scope: $scope,
                limit: null,
                used: $used,
                attempted: $delta,
                planName: $planName,
            );
        }

        return new QuotaCheck(
            allowed: ($used + $delta) <= $limit,
            resource: $resource,
            scope: $scope,
            limit: $limit,
            used: $used,
            attempted: $delta,
            planName: $planName,
        );
    }

    /**
     * Concurrency-safe increment (D3, verbatim shape of
     * PaymentService::nextReceiptSequence()/createReceiptSequenceRow()). MUST
     * run inside the caller's transaction — self-enforced so a rolled-back
     * caller transaction rolls back the increment for free (REQ-CT-02).
     */
    public function consume(string $resource, int $delta = 1, ?QuotaCheck $check = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('QuotaService::consume() must run inside an open transaction.');
        }

        $row = TenantQuotaUsage::where('resource_key', $resource)->lockForUpdate()->first();

        if (! $row) {
            $this->createUsageRow($resource);
            $row = TenantQuotaUsage::where('resource_key', $resource)->lockForUpdate()->firstOrFail();
        }

        $used = (int) $row->getAttributes()['used_count'];

        // REQ-CT-05 — the limit comparison MUST happen inside the locked section,
        // not only in checkConsumption(). That earlier read is unlocked, so under
        // READ COMMITTED two racers at used=2/limit=3 both see room, then serialize
        // here and would both increment to 4 — over-admission. Re-reading `used`
        // under the row lock and comparing again is what makes the last one lose.
        if ($check?->limit !== null && $used + $delta > $check->limit) {
            throw QuotaExceededException::forQuota(new QuotaCheck(
                allowed: false,
                resource: $resource,
                scope: $check->scope,
                limit: $check->limit,
                used: $used,
                attempted: $delta,
                planName: $check->planName,
            ));
        }

        $row->used_count = $used + $delta;
        $row->save();
    }

    /**
     * Create the zero-count counter row inside a nested transaction (Postgres
     * SAVEPOINT) so a concurrent winner's unique violation aborts only the
     * savepoint, not the whole caller transaction (REQ-CT-05); the caller then
     * re-reads the winning row under lock.
     */
    private function createUsageRow(string $resource): void
    {
        try {
            DB::transaction(function () use ($resource): void {
                TenantQuotaUsage::create([
                    'resource_key' => $resource,
                    'used_count' => 0,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the row first — the caller re-reads it.
        }
    }

    /**
     * checkConsumption() + throw-or-consume in one call. Requires an open
     * transaction (see consume()).
     *
     * @throws QuotaExceededException
     */
    public function assertAndConsume(string $resource, int $delta = 1): void
    {
        // REQ-QE-04 — the SAME fail-open guard assertCanRegisterUnits() has.
        // Without it, a call with no tenancy initialized would sail past
        // checkConsumption()'s fail-open (which returns allowed) and reach
        // consume(), where TenantScope::apply() early-returns unscoped: the
        // increment would land on another organization's row, or create one with a
        // null tenant_id and blow up on the NOT NULL constraint — a fail-CLOSED
        // 500 in a guard this requirement says must fail OPEN.
        if (! tenancy()->initialized) {
            return;
        }

        $check = $this->checkConsumption($resource, $delta);

        if (! $check->allowed) {
            throw QuotaExceededException::forQuota($check);
        }

        // The check is passed through so consume() can re-compare the limit under
        // the row lock (REQ-CT-05) — the unlocked read above cannot be trusted to
        // still hold by the time we own the row.
        $this->consume($resource, $delta, $check);
    }

    /**
     * D5 — the `unit` composition: the effective cap is the STRICTER of the
     * billing-band guard (UnitLimitService, unchanged) and the trial/active
     * unit quota. Band-first preserves today's UX (band error first when both
     * would reject); `unit` has no counter row, so the quota side compares the
     * LIVE active count (deleting a unit frees quota by construction).
     *
     * @throws UnitLimitExceededException
     * @throws QuotaExceededException
     */
    public function assertCanRegisterUnits(int $delta = 1): void
    {
        $this->unitLimitService->assertCanIncrease($delta);

        if (! tenancy()->initialized) {
            return;
        }

        // Same cost-control shape as checkConsumption() (D1): resolve BOTH
        // scopes' rows first; only when neither is finite does this skip
        // resolving the trial/active scope (the shipped-OFF fast path).
        $trialLimit = $this->resolveQuota(PlanQuota::RESOURCE_UNIT, PlanQuota::SCOPE_TRIAL);
        $activeLimit = $this->resolveQuota(PlanQuota::RESOURCE_UNIT, PlanQuota::SCOPE_ACTIVE);

        if ($trialLimit === null && $activeLimit === null) {
            return;
        }

        $tenant = Tenant::findOrFail(tenant('id'));
        $scope = $this->subscriptionStateService->quotaScopeFor($tenant);
        $limit = $scope === PlanQuota::SCOPE_TRIAL ? $trialLimit : $activeLimit;

        if ($limit === null) {
            return;
        }

        $activeUnits = $this->unitLimitService->activeUnitCount();
        $attempted = $activeUnits + $delta;

        if ($attempted > $limit) {
            throw QuotaExceededException::forQuota(new QuotaCheck(
                allowed: false,
                resource: PlanQuota::RESOURCE_UNIT,
                scope: $scope,
                limit: $limit,
                used: $activeUnits,
                attempted: $delta,
                planName: $this->resolvePlanName(),
            ));
        }
    }

    /**
     * Visibility payload for /config/subscription (design D8): one row per
     * v1 resource with the resolved limit/usage for the tenant's CURRENT
     * scope. `unit` usage is the LIVE count passed in by the caller (REQ-QV-01
     * — never re-queried here); the other two read `tenant_quota_usage`.
     * `limit: null` represents unlimited explicitly, never a fake large number.
     *
     * @return list<array{resource: string, scope: string, limit: ?int, used: int, remaining: ?int}>
     */
    public function summary(?int $liveUnitCount = null): array
    {
        if (! tenancy()->initialized) {
            return [];
        }

        $tenant = Tenant::findOrFail(tenant('id'));
        $scope = $this->subscriptionStateService->quotaScopeFor($tenant);

        $usageByResource = TenantQuotaUsage::query()
            ->whereIn('resource_key', PlanQuota::RESOURCES)
            ->get()
            ->keyBy('resource_key');

        $summary = [];

        foreach (PlanQuota::RESOURCES as $resource) {
            $limit = $this->resolveQuota($resource, $scope);

            $used = $resource === PlanQuota::RESOURCE_UNIT
                ? ($liveUnitCount ?? $this->unitLimitService->activeUnitCount())
                : (int) ($usageByResource->get($resource)?->used_count ?? 0);

            $summary[] = [
                'resource' => $resource,
                'scope' => $scope,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
            ];
        }

        return $summary;
    }

    /**
     * The tenant's current plan name, for the exception payload / summary.
     * Mirrors UnitLimitService::resolveBand()'s package resolution; null when
     * unresolvable (fail-open territory — never blocks on this being missing).
     */
    private function resolvePlanName(): ?string
    {
        $package = TenantEntitlement::query()
            ->where('tenant_id', tenant('id'))
            ->active()
            ->with('billing_package')
            ->get()
            ->map(fn (TenantEntitlement $entitlement) => $entitlement->billing_package)
            ->filter()
            ->first();

        return $package?->name;
    }
}
