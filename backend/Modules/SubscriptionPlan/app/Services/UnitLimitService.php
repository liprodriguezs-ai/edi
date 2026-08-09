<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Services;

use InvalidArgumentException;
use Modules\Housing\Models\Unit;
use Modules\SubscriptionPlan\Exceptions\UnitLimitExceededException;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use RuntimeException;

/**
 * Guards a tenant's active unit count against a billing-band cap
 * (`billing_package.suggested_unit_max`). Two independent guards live here:
 *
 * - checkIncrease()/assertCanIncrease() — the ENTRY door (unit-limit-enforcement,
 *   shipped `f25114e`): can the tenant's CURRENT band absorb $delta more units?
 *   Enforced on every path that can increase that count — see
 *   UnitObserver::creating()/restoring(), UnitController's batch pre-flights,
 *   and ImportRunner::validate()'s preview.
 * - checkPlanFits()/assertPlanFits() — the EXIT door (plan-downgrade-guard):
 *   does the tenant's CURRENT active unit count fit a TARGET plan's band?
 *   Enforced ONLY at `ConfigurationController::changeBillingPlan`/
 *   `previewBillingPlanChange` (REQ-08) — deliberately NOT here as a
 *   tenancy-wide choke point, because `PreferentialSwapService` (the shared
 *   apply engine both the cluster-admin AND the super-admin preferential swap
 *   call into) must stay exempt for the super-admin actor. See
 *   PreferentialSwapService's class docblock for the bypass rationale.
 *
 * No super-admin exemption on the ENTRY door: checkIncrease() keys on
 * `tenancy()->initialized`, NOT `auth()`/role, so a super admin acting inside
 * a tenant is subject to the same cap. The ONE practical exception is a
 * tenant with no active entitlement (fail-open, see resolveBand()) —
 * `SubscriptionStateService` (`:53-54`) already marks super-admin/lawyer
 * sessions `exempt` + `is_blocked=false` there, so that combination reaches
 * the fail-open branch like any other actor. This is intentional (Decision
 * 4), not an oversight. The EXIT door is deliberately ASYMMETRIC instead
 * (super admin bypasses via the preferential swap) — a separate, later user
 * decision; do not harmonize the two.
 */
class UnitLimitService
{
    public function __construct(
        private readonly PlanPricingService $planPricingService,
    ) {}

    /**
     * Resolve the tenant's currently active billing band. Null when the
     * guard is fail-open: no active entitlement, or the resolved package has
     * no `suggested_unit_max` (also future-proofs an unlimited Enterprise
     * band). When active entitlements reference MORE than one distinct
     * billing package (not observed in production data, but not structurally
     * impossible), the largest cap among them wins and the anomaly is
     * reported for observability — it never throws.
     *
     * @return array{id: string, planName: string, limit: int}|null
     */
    public function resolveBand(): ?array
    {
        $entitlements = TenantEntitlement::query()
            ->where('tenant_id', tenant('id'))
            ->active()
            ->with('billing_package')
            ->get();

        $packages = $entitlements
            ->map(fn (TenantEntitlement $entitlement): ?BillingPackage => $entitlement->billing_package)
            ->filter()
            ->unique('id')
            ->values();

        if ($packages->isEmpty()) {
            return null;
        }

        if ($packages->count() > 1) {
            report(new RuntimeException('Tenant has multiple distinct billing packages across active entitlements'));
        }

        /** @var BillingPackage $package */
        $package = $packages->sortByDesc('suggested_unit_max')->first();

        if ($package->suggested_unit_max === null) {
            return null;
        }

        return [
            'id' => $package->id,
            'planName' => $package->name,
            'limit' => (int) $package->suggested_unit_max,
        ];
    }

    /**
     * The tenant's active unit count. `Unit::count()` is scoped by both
     * `BelongsToTenant` and the default SoftDeletes scope, so this is exactly
     * `deleted_at IS NULL` for the current tenant — the same liveness rule
     * `unit_identity_unique` uses (REQ-06).
     */
    public function activeUnitCount(): int
    {
        return Unit::count();
    }

    /**
     * Check whether increasing the active unit count by $delta is allowed.
     * Fail-open (allowed=true, no numbers resolved) when tenancy is not
     * initialized (Decision 7) or the band is unresolvable (Decision 4) —
     * neither case is a cross-tenant leak, since no query runs against
     * another tenant's data in either branch.
     */
    public function checkIncrease(int $delta): UnitLimitCheck
    {
        if (! tenancy()->initialized) {
            return new UnitLimitCheck(
                allowed: true,
                planName: null,
                unitLimit: null,
                activeUnits: 0,
                attemptedIncrease: $delta,
                suggestedPlanName: null,
            );
        }

        $band = $this->resolveBand();
        $activeUnits = $this->activeUnitCount();

        if ($band === null) {
            return new UnitLimitCheck(
                allowed: true,
                planName: null,
                unitLimit: null,
                activeUnits: $activeUnits,
                attemptedIncrease: $delta,
                suggestedPlanName: null,
            );
        }

        $projected = $activeUnits + $delta;
        $allowed = $projected <= $band['limit'];

        return new UnitLimitCheck(
            allowed: $allowed,
            planName: $band['planName'],
            unitLimit: $band['limit'],
            activeUnits: $activeUnits,
            attemptedIncrease: $delta,
            suggestedPlanName: $allowed ? null : $this->suggestPlanName($projected, $band['id']),
        );
    }

    /**
     * @throws UnitLimitExceededException
     */
    public function assertCanIncrease(int $delta): void
    {
        $check = $this->checkIncrease($delta);

        if (! $check->allowed) {
            throw UnitLimitExceededException::forIncrease($check);
        }
    }

    /**
     * The importer preview's blocking-message variant (REQ-05, REQ-10). Only
     * ever called when $check->allowed is false.
     */
    public function buildImportPreviewMessage(UnitLimitCheck $check): string
    {
        $base = sprintf(
            'Tu plan %s permite hasta %d unidades activas y ya tienes %d; este archivo crearía %d unidades nuevas.',
            $check->planName,
            $check->unitLimit,
            $check->activeUnits,
            $check->attemptedIncrease,
        );

        if ($check->suggestedPlanName !== null) {
            return $base.' Reduce el archivo o cambia al plan '.$check->suggestedPlanName.'.';
        }

        return $base.' Reduce el archivo o contacta a soporte para ampliar tu límite.';
    }

    /**
     * The EXIT-door check (plan-downgrade-guard): does the tenant's CURRENT
     * active unit count fit the TARGET plan's band? Direction-agnostic — an
     * upgrade or a same-band reselect always fits automatically, since no
     * direction is detected, only the boundary condition below.
     *
     * `blocked` ⇔ `$target->suggested_unit_max` is non-null AND strictly less
     * than the active count (Decision 3): exactly-at-limit fits, and a NULL
     * `suggested_unit_max` always fits (future-proofs an unlimited Enterprise
     * band).
     *
     * Fail-open (allowed=true, no numbers resolved) when tenancy is not
     * initialized — mirrors checkIncrease()'s fail-open branch. Every
     * caller of this method is a tenant-scoped config route, so this branch
     * is defensive, not expected to fire in practice.
     */
    public function checkPlanFits(BillingPackage $target): UnitLimitCheck
    {
        if (! tenancy()->initialized) {
            return new UnitLimitCheck(
                allowed: true,
                planName: null,
                unitLimit: null,
                activeUnits: 0,
                attemptedIncrease: 0,
                suggestedPlanName: null,
            );
        }

        $activeUnits = $this->activeUnitCount();
        $limit = $target->suggested_unit_max;
        $allowed = $limit === null || $activeUnits <= $limit;

        return new UnitLimitCheck(
            allowed: $allowed,
            planName: $target->name,
            unitLimit: $limit,
            activeUnits: $activeUnits,
            attemptedIncrease: 0,
            suggestedPlanName: $allowed ? null : $this->suggestPlanName($activeUnits, null),
        );
    }

    /**
     * @throws UnitLimitExceededException
     */
    public function assertPlanFits(BillingPackage $target): void
    {
        $check = $this->checkPlanFits($target);

        if (! $check->allowed) {
            throw UnitLimitExceededException::forPlanChange($check, $this->buildPlanChangeMessage($check));
        }
    }

    /**
     * The plan-change blocking/warning message (REQ-07), mirroring shipped
     * buildImportPreviewMessage(). Only ever called when $check->allowed is
     * false. Mexican Spanish, tú-form.
     */
    public function buildPlanChangeMessage(UnitLimitCheck $check): string
    {
        $base = sprintf(
            'Tu condominio tiene %d unidades activas y el plan %s admite hasta %d.',
            $check->activeUnits,
            $check->planName,
            $check->unitLimit,
        );

        if ($check->suggestedPlanName !== null) {
            return $base.' El plan que se ajusta es '.$check->suggestedPlanName.'.';
        }

        return $base.' Contacta a soporte para encontrar una opción a tu medida.';
    }

    /**
     * Reuses PlanPricingService::planForUnits() (DRY — Decision 6) to name a
     * fitting band for $neededTotal units. Null when the returned plan
     * doesn't actually fit the needed total (planForUnits clamps above the
     * top band to the top band itself, which then fails this fits-check).
     *
     * $excludePlanId is optional: checkIncrease() passes the tenant's CURRENT
     * band id so it never offers a dead-end "upgrade" to the plan the tenant
     * is already on. checkPlanFits() passes null — under target semantics
     * the tenant's own current plan legitimately IS the smallest fitting
     * band, and naming it ("the plan that fits is the one you already have")
     * is the correct, informative answer.
     */
    private function suggestPlanName(int $neededTotal, ?string $excludePlanId): ?string
    {
        try {
            $plan = $this->planPricingService->planForUnits($neededTotal);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($plan->suggested_unit_max === null || $neededTotal > $plan->suggested_unit_max) {
            return null;
        }

        if ($excludePlanId !== null && $plan->id === $excludePlanId) {
            return null;
        }

        return $plan->name;
    }
}
