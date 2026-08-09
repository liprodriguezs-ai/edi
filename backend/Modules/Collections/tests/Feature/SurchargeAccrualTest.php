<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Models\SurchargeMode;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Accrual engine tests for ChargeStatusService::accrueSurcharge (Slice B).
 *
 * Money is in minor units (cents). Percent surcharge_value is BASIS POINTS (500 = 5.00%).
 * The engine is DERIVED + IDEMPOTENT: it recomputes the surcharge from scratch each day and
 * NEVER mutates expected_amount.
 */
final class SurchargeAccrualTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    private ChargeStatusService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChargeStatusService::class);
        $this->tenant = $this->makeTenant('Surcharge Tenant');
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

    /**
     * Create a policy with the given surcharge rules attached.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function makePolicy(array $rules = []): ChargePolicy
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

        $concept = ChargeConcept::create([
            'code' => 'SUR_'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'name' => 'Surcharge concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        $policy = ChargePolicy::create([
            'charge_concept_id' => $concept->id,
            'name' => 'Surcharge policy',
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 1000_00,
            'grace_days' => 5,
            'periodicity_id' => Periodicity::MONTHLY,
            'valid_from' => '2020-01-01',
            'valid_to' => '2030-12-31',
            'is_active' => true,
        ]);

        foreach ($rules as $rule) {
            ChargeSurchargeRule::create(array_merge([
                'charge_policy_id' => $policy->id,
                'from_month' => 1,
                'surcharge_mode_id' => SurchargeMode::PERCENT,
                'surcharge_value' => 500,
                'recurrence' => ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE,
                'cap_amount' => null,
                'is_active' => true,
            ], $rule));
        }

        return $policy->load('surcharge_rules');
    }

    /**
     * Create a charge for the policy/unit. expected 1000.00, due on the given date.
     */
    private function makeCharge(ChargePolicy $policy, Unit $unit, string $dueDate, int $expected = 1000_00, int $paid = 0): Charge
    {
        return Charge::create([
            'charge_policy_id' => $policy->id,
            'charge_concept_id' => $policy->charge_concept_id,
            'unit_id' => $unit->id,
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'issued_at' => $dueDate,
            'due_date' => $dueDate,
            'grace_days' => 5,
            'charge_status_id' => ChargeStatus::PENDING,
            'is_cancelled' => false,
        ]);
    }

    private function surchargeRaw(Charge $charge): int
    {
        return (int) $charge->fresh()->getAttributes()['surcharge_amount'];
    }

    // =========================================================================
    // No surcharge during grace
    // =========================================================================

    public function test_no_surcharge_within_grace_period(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([[]]);
            $unit = $this->makeUnit();
            // due Jun 10 + 5 grace = overdue Jun 15. Today Jun 14 → still in grace.
            $charge = $this->makeCharge($policy, $unit, '2026-06-10');
            Carbon::setTestNow(Carbon::create(2026, 6, 14, 9));

            $this->service->accrueSurcharge($charge, $policy);

            $this->assertSame(0, $this->surchargeRaw($charge), 'No fee while inside the grace window');
        });
    }

    // =========================================================================
    // Accrual after overdue
    // =========================================================================

    public function test_accrues_percent_after_overdue(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([[]]); // 5% per_month_overdue from month 1
            $unit = $this->makeUnit();
            // due May 10 + 5 grace = overdue May 15. Today Jun 20 → past 1 calendar month
            // boundary → monthsOverdue (1-indexed) = 2.
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 6, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            // base = 1000.00, 5% * 2 (1-indexed months) = 100.00 = 10000 cents.
            $this->assertSame(10000, $this->surchargeRaw($charge));
        });
    }

    /**
     * Product decision: the surcharge applies APENAS VENCE — the day after due_date+grace,
     * NOT after a full month of delinquency. A from_month=1 rule fires on day 1 overdue.
     */
    public function test_accrues_on_first_day_overdue(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([[]]); // 5% per_month_overdue from month 1
            $unit = $this->makeUnit();
            // due Jun 10 + 5 grace = overdue Jun 15. Today Jun 16 → exactly 1 day overdue.
            $charge = $this->makeCharge($policy, $unit, '2026-06-10');
            Carbon::setTestNow(Carbon::create(2026, 6, 16, 9));

            $this->service->accrueSurcharge($charge, $policy);

            // monthsOverdue (1-indexed) = 1 → 5% * 1 of 1000.00 = 50.00 = 5000 cents.
            $this->assertSame(5000, $this->surchargeRaw($charge), 'Surcharge accrues the moment the charge passes grace');
        });
    }

    // =========================================================================
    // Escalation by from_month
    // =========================================================================

    public function test_selects_highest_applicable_from_month_step(): void
    {
        $this->tenant->run(function (): void {
            // Step 1: 5% from month 1. Step 2: 10% from month 3 (one_time to isolate the value).
            $policy = $this->makePolicy([
                ['from_month' => 1, 'surcharge_value' => 500, 'recurrence' => ChargeSurchargeRule::RECURRENCE_ONE_TIME],
                ['from_month' => 3, 'surcharge_value' => 1000, 'recurrence' => ChargeSurchargeRule::RECURRENCE_ONE_TIME],
            ]);
            $unit = $this->makeUnit();
            // overdue May 15. Today Sep 20 → monthsOverdue (1-indexed) = 5 → step 2 applies
            // (greatest from_month <= 5 is from_month 3).
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 9, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            // 10% one_time of 1000.00 = 100.00 = 10000 cents (step 2 wins, not step 1).
            $this->assertSame(10000, $this->surchargeRaw($charge));
        });
    }

    // =========================================================================
    // one_time vs per_month_overdue
    // =========================================================================

    public function test_one_time_applies_value_once_regardless_of_months(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([
                ['recurrence' => ChargeSurchargeRule::RECURRENCE_ONE_TIME, 'surcharge_value' => 500],
            ]);
            $unit = $this->makeUnit();
            // overdue May 15. Today Aug 20 → monthsOverdue (1-indexed) = 4, but one_time → applied once.
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            // 5% one_time of 1000.00 = 50.00 = 5000 cents (NOT * 4).
            $this->assertSame(5000, $this->surchargeRaw($charge));
        });
    }

    public function test_per_month_overdue_scales_by_months(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([
                ['recurrence' => ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE, 'surcharge_value' => 500],
            ]);
            $unit = $this->makeUnit();
            // overdue May 15. Today Aug 20 → monthsOverdue (1-indexed) = 4.
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            // 5% * 4 months (1-indexed) of 1000.00 = 200.00 = 20000 cents.
            $this->assertSame(20000, $this->surchargeRaw($charge));
        });
    }

    // =========================================================================
    // Cap
    // =========================================================================

    public function test_cap_amount_limits_total_surcharge(): void
    {
        $this->tenant->run(function (): void {
            // 5% per month, but capped at 80.00.
            $policy = $this->makePolicy([
                ['recurrence' => ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE, 'surcharge_value' => 500, 'cap_amount' => 80_00],
            ]);
            $unit = $this->makeUnit();
            // overdue May 15. Today Aug 20 → monthsOverdue (1-indexed) = 4 → uncapped 200.00,
            // capped to 80.00.
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            $this->assertSame(8000, $this->surchargeRaw($charge), 'Surcharge capped at cap_amount');
        });
    }

    // =========================================================================
    // Stops at paid (base → 0)
    // =========================================================================

    public function test_surcharge_stops_when_principal_fully_paid(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([[]]);
            $unit = $this->makeUnit();
            // Fully paid (paid == expected) → base 0 → surcharge 0 even though overdue.
            $charge = $this->makeCharge($policy, $unit, '2026-05-10', 1000_00, 1000_00);
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            $this->assertSame(0, $this->surchargeRaw($charge), 'Cleared balance stops the fee');
        });
    }

    // =========================================================================
    // No compound interest (base on expected - discount, not surcharge)
    // =========================================================================

    public function test_no_compound_interest_base_excludes_prior_surcharge(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([
                ['recurrence' => ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE, 'surcharge_value' => 500],
            ]);
            $unit = $this->makeUnit();
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 6, 20, 9)); // monthsOverdue (1-indexed) = 2 → 100.00

            $this->service->accrueSurcharge($charge, $policy);
            $this->assertSame(10000, $this->surchargeRaw($charge));

            // Next period: base must still be 1000.00 (NOT 1100.00). 5% * 3 = 150.00.
            Carbon::setTestNow(Carbon::create(2026, 7, 20, 9)); // monthsOverdue (1-indexed) = 3
            $charge->refresh();
            $this->service->accrueSurcharge($charge, $policy);

            // If it compounded, the fee would exceed 150.00. It must be exactly 150.00 (base
            // stays the original 1000.00 principal, never includes the prior surcharge).
            $this->assertSame(15000, $this->surchargeRaw($charge), 'Base never includes prior surcharge');
        });
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    public function test_rerunning_same_day_is_idempotent(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([
                ['recurrence' => ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE, 'surcharge_value' => 500],
            ]);
            $unit = $this->makeUnit();
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9)); // monthsOverdue (1-indexed) = 4 → 200.00

            $this->service->accrueSurcharge($charge, $policy);
            $first = $this->surchargeRaw($charge);

            $charge->refresh();
            $this->service->accrueSurcharge($charge, $policy);
            $second = $this->surchargeRaw($charge);

            $this->assertSame(20000, $first);
            $this->assertSame($first, $second, 'Re-running the same day does not accumulate');
        });
    }

    // =========================================================================
    // No policy → 0
    // =========================================================================

    public function test_charge_without_policy_accrues_nothing(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([[]]);
            $unit = $this->makeUnit();
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            // Detach the policy on the charge itself.
            $charge->charge_policy_id = null;
            $charge->save();
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $this->service->accrueSurcharge($charge, null);

            $this->assertSame(0, $this->surchargeRaw($charge));
        });
    }

    // =========================================================================
    // expected_amount is never mutated
    // =========================================================================

    public function test_accrual_never_mutates_expected_amount(): void
    {
        $this->tenant->run(function (): void {
            $policy = $this->makePolicy([[]]);
            $unit = $this->makeUnit();
            $charge = $this->makeCharge($policy, $unit, '2026-05-10');
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $this->service->accrueSurcharge($charge, $policy);

            $this->assertSame(1000_00, (int) $charge->fresh()->getAttributes()['expected_amount']);
        });
    }
}
