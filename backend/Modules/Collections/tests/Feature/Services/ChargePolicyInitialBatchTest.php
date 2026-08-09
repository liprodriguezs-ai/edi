<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature\Services;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargePolicyService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * ChargePolicyService::create generates each policy's first batch(es) immediately:
 *  - PERIODIC: a PURE TEMPLATE (charge_batch.is_initial = true, DRAFT, NO charges) PLUS the
 *    FIRST period materialized as an ACTIVATED clone (is_initial = false, WITH charges),
 *    derived from valid_from.
 *  - DIRECT (EVENTUAL): a single ACTIVATED one-shot batch (is_initial = false, month = null,
 *    WITH charges). No template — eventual never clones.
 *
 * The template never carries charges; the cron clones it for periods 2, 3, …
 */
final class ChargePolicyInitialBatchTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function makeConcept(Tenant $tenant): ChargeConcept
    {
        $concept = null;
        $tenant->run(function () use (&$concept): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $concept = ChargeConcept::create([
                'code' => 'INIT_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Init batch concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
        });

        return $concept;
    }

    public function test_periodic_policy_creates_template_plus_activated_first_period_clone(): void
    {
        $tenant = $this->makeTenant('Periodic Initial Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeConcept($tenant);

        // Anchor "today" inside the first period so the derived first-period clone is current.
        Carbon::setTestNow(Carbon::create(2026, 1, 10, 7, 0, 0));

        $tenant->run(function () use ($concept, $unit): void {
            $policy = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ]);

            // The template: is_initial = true, DRAFT, period-agnostic, NO charges.
            $template = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', true)
                ->first();

            $this->assertNotNull($template, 'A periodic policy must own an initial template batch');
            $this->assertSame('all', $template->target_type);
            $this->assertNull($template->month, 'The template is period-agnostic (month is null)');
            $this->assertSame(ChargeBatchStatus::DRAFT, $template->charge_batch_status_id, 'The template stays DRAFT');
            $this->assertSame(
                0,
                Charge::where('charge_batch_id', $template->id)->count(),
                'The template must NEVER carry charges',
            );

            // Exactly one initial template per policy.
            $this->assertSame(
                1,
                ChargeBatch::where('charge_policy_id', $policy->id)->where('is_initial', true)->count(),
            );

            // The first period: a CLONE (is_initial = false), ACTIVATED, with charges.
            $firstPeriod = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->first();

            $this->assertNotNull($firstPeriod, 'The first period must exist as an activated clone');
            // Derived from valid_from (2026-01) via the resolver.
            $this->assertSame(2026, $firstPeriod->year);
            $this->assertSame(1, $firstPeriod->month);
            $this->assertSame(ChargeBatchStatus::ACTIVE, $firstPeriod->charge_batch_status_id);
            $this->assertSame(
                1,
                Charge::where('charge_batch_id', $firstPeriod->id)->where('unit_id', $unit->id)->count(),
                'The first-period clone must be activated and charge its target unit',
            );
        });
    }

    public function test_direct_policy_creates_single_activated_one_shot_batch(): void
    {
        $tenant = $this->makeTenant('Direct Initial Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeConcept($tenant);

        $tenant->run(function () use ($concept, $unit): void {
            $policy = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $concept->id,
                'name' => 'Multa única',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ]);

            $batch = ChargeBatch::where('charge_policy_id', $policy->id)->first();

            $this->assertNotNull($batch, 'A direct policy must create its one-shot batch');
            $this->assertFalse(
                (bool) $batch->is_initial,
                'A direct one-shot batch is NOT a template (is_initial = false) so it appears in COBROS GENERADOS',
            );
            $this->assertNull($batch->month, 'A direct one-shot batch has no month');
            $this->assertSame('2026-03-01', $batch->issued_at->toDateString());
            $this->assertSame(ChargeBatchStatus::ACTIVE, $batch->charge_batch_status_id);
            $this->assertSame(
                1,
                Charge::where('charge_batch_id', $batch->id)->where('unit_id', $unit->id)->count(),
            );

            // A direct policy never spawns a template batch.
            $this->assertSame(
                0,
                ChargeBatch::where('charge_policy_id', $policy->id)->where('is_initial', true)->count(),
                'A direct policy must NOT create an is_initial template',
            );
        });
    }

    /**
     * Fix 3 — A periodic policy with a FUTURE valid_from must create only the template
     * (no charges, no first-period clone). The cron generates the first real period
     * when the validity window opens (activePoliciesFor filters whereDate('valid_from','<=',today)).
     */
    public function test_periodic_policy_with_future_valid_from_creates_only_template(): void
    {
        $tenant = $this->makeTenant('Future ValidFrom Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeConcept($tenant);

        // Anchor "today" to a date BEFORE the policy's valid_from so the validity window
        // is in the future.
        Carbon::setTestNow(Carbon::create(2026, 1, 10, 7, 0, 0));

        $tenant->run(function () use ($concept, $unit): void {
            $policy = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota futura',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-03-01', // 2 months in the future
                'valid_to' => null,
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ]);

            // One template must be created (is_initial=true, DRAFT, no charges).
            $template = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', true)
                ->first();

            $this->assertNotNull($template, 'A periodic policy must own a template even when valid_from is future');
            $this->assertSame(ChargeBatchStatus::DRAFT, $template->charge_batch_status_id, 'Template stays DRAFT');
            $this->assertNull($template->month, 'Template is period-agnostic (month is null)');
            $this->assertSame(
                0,
                Charge::where('charge_batch_id', $template->id)->count(),
                'The template must NEVER carry charges',
            );

            // No first-period clone: valid_from is in the future so no period is active yet.
            $this->assertSame(
                0,
                ChargeBatch::where('charge_policy_id', $policy->id)
                    ->where('is_initial', false)
                    ->count(),
                'A future valid_from must NOT materialize the first-period clone immediately',
            );

            // The unit must have no charges from this policy.
            $this->assertSame(
                0,
                Charge::whereHas('batch', fn ($q) => $q->where('charge_policy_id', $policy->id))
                    ->where('unit_id', $unit->id)
                    ->count(),
                'No charges for the unit until the validity window opens',
            );
        });
    }

    /**
     * The collection mode is DERIVED from the periodicity — there is no stored
     * collection_mode column. EVENTUAL (the only non-automatic periodicity) yields a
     * one-shot DIRECT policy; any automatic periodicity yields a recurrent PERIODIC one.
     */
    public function test_collection_mode_is_derived_from_periodicity_without_a_column(): void
    {
        // The redundant column was removed at the root.
        $this->assertFalse(
            Schema::hasColumn('charge_policy', 'collection_mode'),
            'charge_policy must not carry a redundant collection_mode column',
        );

        $tenant = $this->makeTenant('Derived Mode Tenant');
        $this->seedTenant($tenant);
        $concept = $this->makeConcept($tenant);

        $tenant->run(function () use ($concept): void {
            $eventual = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $concept->id,
                'name' => 'Multa única',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ]);

            // EVENTUAL → direct (one-shot): a single is_initial = false monthless batch, no template.
            $this->assertTrue($eventual->isDirect(), 'EVENTUAL periodicity must be a direct policy');
            $this->assertFalse($eventual->isPeriodic());
            $this->assertSame(
                0,
                ChargeBatch::where('charge_policy_id', $eventual->id)->where('is_initial', true)->count(),
                'A direct (EVENTUAL) policy must NOT create a template',
            );
            $eventualBatch = ChargeBatch::where('charge_policy_id', $eventual->id)
                ->where('is_initial', false)
                ->firstOrFail();
            $this->assertNull($eventualBatch->month, 'A direct (EVENTUAL) policy bills a single monthless batch');

            $periodicConcept = ChargeConcept::create([
                'code' => 'PER_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Cuota mensual',
                'charge_concept_category_id' => $concept->charge_concept_category_id,
                'is_active' => true,
            ]);

            $monthly = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $periodicConcept->id,
                'name' => 'Cuota mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ]);

            // MONTHLY → periodic (recurrent): a period-agnostic template (month null, no charges)
            // the cron clones, PLUS an activated first-period clone that carries the month.
            $this->assertTrue($monthly->isPeriodic(), 'MONTHLY periodicity must be a periodic policy');
            $this->assertFalse($monthly->isDirect());
            $monthlyTemplate = ChargeBatch::where('charge_policy_id', $monthly->id)
                ->where('is_initial', true)
                ->firstOrFail();
            $this->assertNull($monthlyTemplate->month, 'A periodic template is period-agnostic (month null)');
            $this->assertSame(
                0,
                Charge::where('charge_batch_id', $monthlyTemplate->id)->count(),
                'A periodic template never carries charges',
            );
            $monthlyFirstPeriod = ChargeBatch::where('charge_policy_id', $monthly->id)
                ->where('is_initial', false)
                ->firstOrFail();
            $this->assertNotNull($monthlyFirstPeriod->month, 'The first-period clone carries the billed month');
        });
    }
}
