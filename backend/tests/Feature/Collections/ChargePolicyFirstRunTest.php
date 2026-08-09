<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
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
 * ChargePolicyService::resolveFirstRun — the FIRST period of a periodic policy.
 *
 * ANCHORED BILLING PERIODS: the first period always STARTS exactly on valid_from, no
 * matter how far in the past that is or whether "today" would put it in a closed window.
 * There is no re-anchor to today anymore (that stale-period correction was deleted — see
 * design decision 7): a backdated valid_from creates its first period directly at that
 * date, and the catch-up chain fills in any remaining periods up to today (at creation —
 * see ChargePolicyCreationBacklogTest — and afterwards in the daily cron). These tests pin
 * only where the chain STARTS:
 *
 *  - retroactive valid_from (however old)  → first period starts exactly there, no re-anchor
 *  - a mid-period valid_from                → still the literal anchor, not the calendar bound
 *  - future valid_from                      → template only, no charges (unaffected)
 *  - already-expired valid_to               → still anchors at valid_from (unaffected)
 */
final class ChargePolicyFirstRunTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

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

    /**
     * Create a periodic policy through the SERVICE (template + first-period clone).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createPolicy(Tenant $tenant, string $periodicityId, array $overrides = []): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $periodicityId, $overrides): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'FIRSTRUN_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Cuota de prueba',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = app(ChargePolicyService::class)->create(array_merge([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota de prueba',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => $periodicityId,
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ], $overrides));
        });

        return $policy;
    }

    /**
     * The FIRST activated period batch (is_initial = false) of a policy, in chain order.
     *
     * A retroactive valid_from now materializes the whole backlog at creation (see
     * ChargePolicyCreationBacklogTest), so ordering is mandatory here: these tests are about
     * where the chain STARTS, and an unordered first() would silently assert on whichever
     * period the driver happened to return.
     */
    private function firstPeriodBatch(Tenant $tenant, ChargePolicy $policy): ?ChargeBatch
    {
        $batch = null;
        $tenant->run(function () use (&$batch, $policy): void {
            $batch = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->whereNull('deleted_at')
                ->orderBy('period_start')
                ->first();
        });

        return $batch;
    }

    /** The DRAFT template batch (is_initial = true) of a periodic policy. */
    private function templateBatch(Tenant $tenant, ChargePolicy $policy): ?ChargeBatch
    {
        $batch = null;
        $tenant->run(function () use (&$batch, $policy): void {
            $batch = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', true)
                ->whereNull('deleted_at')
                ->first();
        });

        return $batch;
    }

    // =========================================================================
    // Retroactive valid_from → anchors exactly there, no re-anchor to today
    // =========================================================================

    public function test_retroactive_valid_from_anchors_exactly_there(): void
    {
        $tenant = $this->makeTenant('FirstRun Retroactive Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        // "Today" is 23 days after valid_from — under anchored periods this is irrelevant
        // to where the first period starts; it only matters for the cron's later catch-up.
        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::EVERY_TWO_DAYS, [
            'valid_from' => '2026-07-01',
            'valid_to' => '2026-12-31',
        ]);

        $batch = $this->firstPeriodBatch($tenant, $policy);

        // periodFor(EVERY_TWO_DAYS, 2026-07-01) starts EXACTLY on the anchor: [01/07, 02/07].
        // The deleted epoch grid used to return [30/06, 01/07] here — a period opening one
        // day BEFORE the policy was valid, which the product rule forbids.
        $this->assertNotNull($batch, 'The first period must be materialized at creation');
        $this->assertSame(
            '2026-07-01',
            $batch->issued_at->toDateString(),
            'The first period starts exactly on valid_from — never a day earlier',
        );
        $this->assertSame('2026-07-02', $batch->due_date->toDateString(), 'End = start + 2 days − 1');
        $this->assertSame(1, $batch->cycle, 'cycle = start day of the anchored period');
    }

    public function test_retroactive_valid_from_anchors_exactly_there_monthly_too(): void
    {
        $tenant = $this->makeTenant('FirstRun RetroMonthly Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        // valid_from lands in January 2026 — under anchored periods the first period still
        // starts exactly there, regardless of how long ago that was.
        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $batch = $this->firstPeriodBatch($tenant, $policy);

        $this->assertNotNull($batch);
        $this->assertSame(2026, $batch->year);
        $this->assertSame(1, $batch->month, 'No re-anchor: the first period starts at valid_from, not "today"');
        $this->assertSame('2026-01-10', $batch->issued_at->toDateString());
        $this->assertSame('2026-02-09', $batch->due_date->toDateString(), 'End = start + 1 month - 1 day');
    }

    // =========================================================================
    // Mid-period valid_from → the literal anchor, not the calendar-month bound
    // =========================================================================

    public function test_valid_from_mid_month_anchors_the_literal_date(): void
    {
        $tenant = $this->makeTenant('FirstRun MidMonth Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        // Anchored periods never round down to the 1st of the calendar month: the first
        // period starts exactly at the entered valid_from (spec "New MONTHLY policy
        // anchored mid-month").
        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-07-03',
            'valid_to' => null,
        ]);

        $batch = $this->firstPeriodBatch($tenant, $policy);

        $this->assertNotNull($batch);
        $this->assertSame(7, $batch->month);
        $this->assertSame('2026-07-03', $batch->issued_at->toDateString(), 'Anchored exactly on valid_from, not day 1');
        $this->assertSame('2026-08-02', $batch->due_date->toDateString(), 'End = start + 1 month - 1 day');
    }

    // =========================================================================
    // Future valid_from → template only, no re-anchor
    // =========================================================================

    public function test_future_valid_from_keeps_its_own_future_window_and_creates_only_the_template(): void
    {
        $tenant = $this->makeTenant('FirstRun Future Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        // The period opens on the anchor itself: [11/08, 12/08].
        $policy = $this->createPolicy($tenant, Periodicity::EVERY_TWO_DAYS, [
            'valid_from' => '2026-08-11',
            'valid_to' => '2026-12-31',
        ]);

        $this->assertNull(
            $this->firstPeriodBatch($tenant, $policy),
            'A future valid_from must not materialize charges yet — only the template',
        );

        $template = $this->templateBatch($tenant, $policy);
        $this->assertNotNull($template);
        $this->assertSame(
            '2026-08-12',
            $template->due_date->toDateString(),
            'The template describes the future period anchored on valid_from',
        );
        $this->assertSame('2026-08-11', $template->issued_at->toDateString());
    }

    // =========================================================================
    // Expired valid_to → no re-anchor (never bill outside the validity window)
    // =========================================================================

    public function test_expired_valid_to_keeps_the_past_period_instead_of_jumping_to_today(): void
    {
        $tenant = $this->makeTenant('FirstRun Expired Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-03-01',
            'valid_to' => '2026-03-31',
        ]);

        $batch = $this->firstPeriodBatch($tenant, $policy);

        $this->assertNotNull($batch);
        $this->assertSame(3, $batch->month, 'An expired policy must never be moved to a period outside its window');
        $this->assertSame('2026-03-31', $batch->due_date->toDateString());
    }
}
