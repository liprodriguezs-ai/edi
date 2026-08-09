<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * due_soon_days override: per-policy value shadows the cluster setting.
 *
 * Pattern mirrors grace_days:
 *  - charge_policy.due_soon_days: nullable override; null = use cluster default.
 *  - charge.due_soon_days:        snapshot at generation time; null = use cluster default at FSM time.
 *  - FSM (resolveStatusKey): charge.due_soon_days ?? thresholds['due_soon_days'] (cluster setting).
 *
 * Tests:
 *  (a) Override applied    — policy.due_soon_days = 3, cluster = 7 → FSM uses 3.
 *  (b) Fallback to cluster — policy.due_soon_days = null → FSM uses cluster (7).
 *  (c) Transition accuracy — per-rule due_soon_days correctly drives DUE_SOON status.
 */
final class DueSoonDaysOverrideTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private ChargeStatusService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChargeStatusService::class);
        $this->tenant = $this->makeTenant('DueSoon Override Tenant');
        $this->seedTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(): Unit
    {
        $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
        $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

        return Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => (string) mt_rand(1, 99999),
            'unit_status_id' => $unitStatus->id,
            'ownership_share' => 1.0,
        ]);
    }

    private function makePolicy(?int $dueSoonDays): ChargePolicy
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

        $concept = ChargeConcept::create([
            'code' => 'DS_'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'name' => 'DueSoon test concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        return ChargePolicy::create([
            'charge_concept_id' => $concept->id,
            'name' => 'DueSoon policy',
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 1000_00,
            'grace_days' => 0,
            'due_soon_days' => $dueSoonDays,
            'periodicity_id' => Periodicity::MONTHLY,
            'valid_from' => '2020-01-01',
            'valid_to' => '2030-12-31',
            'is_active' => true,
        ]);
    }

    /**
     * Build a charge whose due_soon_days snapshot mirrors what ChargeGenerationService writes.
     */
    private function makeCharge(ChargePolicy $policy, Unit $unit, string $dueDate): Charge
    {
        return Charge::create([
            'charge_policy_id' => $policy->id,
            'charge_concept_id' => $policy->charge_concept_id,
            'unit_id' => $unit->id,
            'expected_amount' => 1000_00,
            'paid_amount' => 0,
            'issued_at' => $dueDate,
            'due_date' => $dueDate,
            'grace_days' => $policy->grace_days,
            'due_soon_days' => $policy->due_soon_days, // snapshot exactly as ChargeGenerationService does
            'charge_status_id' => ChargeStatus::PENDING,
            'is_cancelled' => false,
        ]);
    }

    // =========================================================================
    // (a) Override applied
    // =========================================================================

    /**
     * When policy.due_soon_days = 3 and cluster setting = 7, the FSM uses 3.
     * The charge is DUE_SOON 3 days before due_date but PENDING 4 days before.
     */
    public function test_policy_override_shadows_cluster_setting(): void
    {
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(3);

            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            $this->assertSame(3, $charge->due_soon_days, 'charge.due_soon_days must snapshot the policy override');

            // Jun 16: 4 days before due_date — outside the 3-day override window → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 16, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 17: 3 days before due_date — at the override window boundary → DUE_SOON.
            Carbon::setTestNow(Carbon::create(2026, 6, 17, 9));
            $this->assertSame(ChargeStatus::DUE_SOON, $this->service->resolveStatusKey($charge));
        });
    }

    /**
     * A tighter override (2 days) suppresses DUE_SOON for dates the cluster (7 days)
     * would otherwise catch — the override is strictly respected.
     */
    public function test_tight_override_suppresses_cluster_window(): void
    {
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(2);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            // Jun 15: 5 days before — inside cluster window (7) but outside override (2) → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 15, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 18: 2 days before — inside override (2) window → DUE_SOON.
            Carbon::setTestNow(Carbon::create(2026, 6, 18, 9));
            $this->assertSame(ChargeStatus::DUE_SOON, $this->service->resolveStatusKey($charge));
        });
    }

    // =========================================================================
    // (b) Fallback to cluster when policy.due_soon_days is null
    // =========================================================================

    /**
     * When charge.due_soon_days is null (no policy override) the FSM falls back
     * to the cluster's collections.due_soon_days setting.
     */
    public function test_null_due_soon_days_falls_back_to_cluster_setting(): void
    {
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 5, 'type' => Setting::TYPE_INTEGER],
            );

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(null);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            $this->assertNull($charge->due_soon_days, 'No override → charge.due_soon_days must be null');

            // Jun 14: 6 days before — outside cluster 5-day window → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 14, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 15: 5 days before — inside cluster window → DUE_SOON.
            Carbon::setTestNow(Carbon::create(2026, 6, 15, 9));
            $this->assertSame(ChargeStatus::DUE_SOON, $this->service->resolveStatusKey($charge));
        });
    }

    /**
     * When no cluster setting exists, the hard-coded default (7 days) governs.
     */
    public function test_null_due_soon_days_with_no_cluster_setting_uses_default(): void
    {
        $this->tenant->run(function (): void {
            Setting::where('slug', 'collections.due_soon_days')->delete();

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(null);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            // Jun 12: 8 days before — outside default 7-day window → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 12, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 13: 7 days before — exactly at the default boundary → DUE_SOON.
            Carbon::setTestNow(Carbon::create(2026, 6, 13, 9));
            $this->assertSame(ChargeStatus::DUE_SOON, $this->service->resolveStatusKey($charge));
        });
    }

    // =========================================================================
    // (c) Transition accuracy
    // =========================================================================

    /**
     * On due_date itself the charge is DUE_SOON (window is inclusive on the right).
     * The day after (with grace_days = 0) → OVERDUE.
     */
    public function test_due_soon_is_inclusive_and_overdue_follows(): void
    {
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(3);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            // Jun 20 (due_date, grace_days = 0): DUE_SOON (still in window).
            Carbon::setTestNow(Carbon::create(2026, 6, 20, 9));
            $this->assertSame(ChargeStatus::DUE_SOON, $this->service->resolveStatusKey($charge));

            // Jun 21 (day after due_date, grace_days = 0): OVERDUE.
            Carbon::setTestNow(Carbon::create(2026, 6, 21, 9));
            $this->assertSame(ChargeStatus::OVERDUE, $this->service->resolveStatusKey($charge));
        });
    }

    /**
     * due_soon_days = 0 means the look-ahead window is zero days: DUE_SOON fires only
     * on the exact due_date itself.  One day before → still PENDING; one day after
     * (grace_days = 0) → OVERDUE.  Anchors the lower boundary of the override range.
     */
    public function test_zero_due_soon_days_triggers_only_on_exact_due_date(): void
    {
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(0);  // zero-day window override
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            $this->assertSame(0, $charge->due_soon_days, 'charge.due_soon_days must snapshot the zero-day override');

            // Jun 19: 1 day before due_date — window is 0, so no look-ahead → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 19, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 20: exact due_date — window of 0 means the boundary itself is DUE_SOON.
            Carbon::setTestNow(Carbon::create(2026, 6, 20, 9));
            $this->assertSame(ChargeStatus::DUE_SOON, $this->service->resolveStatusKey($charge));

            // Jun 21: 1 day past due_date, grace_days = 0 → OVERDUE.
            Carbon::setTestNow(Carbon::create(2026, 6, 21, 9));
            $this->assertSame(ChargeStatus::OVERDUE, $this->service->resolveStatusKey($charge));
        });
    }

    /**
     * recomputeCharge correctly persists the status driven by the per-policy
     * due_soon_days (integration path: resolveStatusKey → DB write).
     */
    public function test_recompute_charge_persists_due_soon_from_override(): void
    {
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(3);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            // 3 days before due_date — within override window → should become DUE_SOON.
            Carbon::setTestNow(Carbon::create(2026, 6, 17, 9));

            $this->service->recomputeCharge($charge);

            $this->assertSame(
                ChargeStatus::DUE_SOON,
                $charge->fresh()->charge_status_id,
                'recomputeCharge must persist DUE_SOON driven by the per-policy due_soon_days override'
            );
        });
    }
}
