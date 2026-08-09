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
 * grace_days cluster default: when a policy/charge leaves grace empty (null), the FSM
 * resolves the EFFECTIVE grace from the cluster collections.grace_days setting (default
 * 5) instead of collapsing to 0 (which would make moroso = due_date).
 *
 * Pattern mirrors due_soon_days exactly (see DueSoonDaysOverrideTest):
 *  - charge_policy.grace_days: nullable override; null = use cluster default.
 *  - charge.grace_days:        snapshot at generation; null = resolve at FSM time.
 *  - FSM (resolveStatusKey): charge.grace_days ?? thresholds['grace_days'] (cluster setting).
 *
 * overdueDate = due_date + effective grace. A charge is OVERDUE only once today is AFTER
 * overdueDate; on/within the grace window it stays PENDING.
 */
final class GraceDaysDefaultTest extends TestCase
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
        $this->tenant = $this->makeTenant('Grace Default Tenant');
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

    private function makePolicy(?int $graceDays): ChargePolicy
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

        $concept = ChargeConcept::create([
            'code' => 'GR_'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'name' => 'Grace test concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        return ChargePolicy::create([
            'charge_concept_id' => $concept->id,
            'name' => 'Grace policy',
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 1000_00,
            'grace_days' => $graceDays,
            // due_soon_days = 0 → the "por vencer" look-behind only fires on due_date itself,
            // so it never interferes with the post-due grace boundaries under test.
            'due_soon_days' => 0,
            'periodicity_id' => Periodicity::MONTHLY,
            'valid_from' => '2020-01-01',
            'valid_to' => '2030-12-31',
            'is_active' => true,
        ]);
    }

    /**
     * Build a charge whose grace_days snapshot mirrors what ChargeGenerationService writes
     * (policy value as-is; null stays null and is resolved at FSM time).
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
            'due_soon_days' => $policy->due_soon_days,
            'charge_status_id' => ChargeStatus::PENDING,
            'is_cancelled' => false,
        ]);
    }

    private function setClusterGrace(int $days): void
    {
        Setting::updateOrCreate(
            ['slug' => 'collections.grace_days'],
            ['name' => 'collections.grace_days', 'slug' => 'collections.grace_days', 'value' => $days, 'type' => Setting::TYPE_INTEGER],
        );
    }

    // =========================================================================
    // (a) Fallback to cluster default when grace is null — the core fix
    // =========================================================================

    /**
     * When charge.grace_days is null (no policy override) the FSM falls back to the
     * cluster's collections.grace_days. With cluster grace = 5 and due_date = Jun 20,
     * the charge stays PENDING through Jun 25 (due + 5) and only becomes OVERDUE on Jun 26.
     *
     * REGRESSION ANCHOR: the day after due_date (Jun 21) used to be OVERDUE (grace collapsed
     * to 0). It must now be PENDING — the cluster grace is honored.
     */
    public function test_null_grace_falls_back_to_cluster_setting(): void
    {
        $this->tenant->run(function (): void {
            $this->setClusterGrace(5);

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(null);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            $this->assertNull($charge->grace_days, 'No override → charge.grace_days must be null');

            // Jun 21: 1 day past due_date but INSIDE the cluster 5-day grace → PENDING
            // (would have been OVERDUE under the old grace-collapses-to-0 behavior).
            Carbon::setTestNow(Carbon::create(2026, 6, 21, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 25: due_date + 5 (exact overdueDate) — today is NOT after it → still PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 25, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 26: due_date + 6 — past the effective grace window → OVERDUE.
            Carbon::setTestNow(Carbon::create(2026, 6, 26, 9));
            $this->assertSame(ChargeStatus::OVERDUE, $this->service->resolveStatusKey($charge));
        });
    }

    // =========================================================================
    // (b) Per-policy override shadows the cluster grace
    // =========================================================================

    /**
     * policy.grace_days = 10 shadows the cluster grace (5): the charge stays PENDING
     * through due_date + 10 and only becomes OVERDUE afterwards.
     */
    public function test_policy_override_shadows_cluster_grace(): void
    {
        $this->tenant->run(function (): void {
            $this->setClusterGrace(5);

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(10);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            $this->assertSame(10, $charge->grace_days, 'charge.grace_days must snapshot the policy override');

            // Jun 26: due + 6 — cluster (5) would be OVERDUE, but the 10-day override → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 26, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jul 1: due + 11 — past the 10-day override window → OVERDUE.
            Carbon::setTestNow(Carbon::create(2026, 7, 1, 9));
            $this->assertSame(ChargeStatus::OVERDUE, $this->service->resolveStatusKey($charge));
        });
    }

    // =========================================================================
    // (c) No cluster setting → hard-coded default (5)
    // =========================================================================

    /**
     * When no cluster setting exists, the documented default (5 days) governs.
     */
    public function test_null_grace_with_no_cluster_setting_uses_default(): void
    {
        $this->tenant->run(function (): void {
            Setting::where('slug', 'collections.grace_days')->delete();

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(null);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            // Jun 25: due + 5 (default boundary) — not yet after → PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 25, 9));
            $this->assertSame(ChargeStatus::PENDING, $this->service->resolveStatusKey($charge));

            // Jun 26: due + 6 — past the default 5-day grace → OVERDUE.
            Carbon::setTestNow(Carbon::create(2026, 6, 26, 9));
            $this->assertSame(ChargeStatus::OVERDUE, $this->service->resolveStatusKey($charge));
        });
    }

    // =========================================================================
    // (d) Explicit grace = 0 is respected (direct charges) — cluster must NOT override it
    // =========================================================================

    /**
     * An explicit grace_days = 0 (e.g. a direct/counter charge) is a real value, not a
     * missing one: the ?? fallback must NOT replace it with the cluster default. Moroso
     * fires the day after due_date even when the cluster grace is 5.
     */
    public function test_explicit_zero_grace_is_not_overridden_by_cluster(): void
    {
        $this->tenant->run(function (): void {
            $this->setClusterGrace(5);

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(0);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            $this->assertSame(0, $charge->grace_days, 'charge.grace_days must snapshot the explicit 0');

            // Jun 21: 1 day past due_date, grace 0 → OVERDUE (cluster 5 does NOT apply).
            Carbon::setTestNow(Carbon::create(2026, 6, 21, 9));
            $this->assertSame(ChargeStatus::OVERDUE, $this->service->resolveStatusKey($charge));
        });
    }

    // =========================================================================
    // (e) Integration: recomputeCharge persists the cluster-grace-driven status
    // =========================================================================

    /**
     * recomputeCharge (resolveStatusKey → DB write) persists the status driven by the
     * cluster grace fallback: within the window it must NOT flip a null-grace charge to
     * OVERDUE on the day after due_date.
     */
    public function test_recompute_charge_respects_cluster_grace(): void
    {
        $this->tenant->run(function (): void {
            $this->setClusterGrace(5);

            $unit = $this->makeUnit();
            $policy = $this->makePolicy(null);
            $charge = $this->makeCharge($policy, $unit, '2026-06-20');

            // Jun 21: inside cluster grace → persisted status stays PENDING.
            Carbon::setTestNow(Carbon::create(2026, 6, 21, 9));
            $this->service->recomputeCharge($charge);
            $this->assertSame(
                ChargeStatus::PENDING,
                $charge->fresh()->charge_status_id,
                'recomputeCharge must keep a null-grace charge PENDING within the cluster grace window'
            );

            // Jun 26: past cluster grace → persisted status becomes OVERDUE.
            Carbon::setTestNow(Carbon::create(2026, 6, 26, 9));
            $this->service->recomputeCharge($charge);
            $this->assertSame(
                ChargeStatus::OVERDUE,
                $charge->fresh()->charge_status_id,
                'recomputeCharge must mark OVERDUE once past due_date + cluster grace'
            );
        });
    }
}
