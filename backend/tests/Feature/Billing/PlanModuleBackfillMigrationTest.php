<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Self-contained coverage for migration
 * 2026_07_22_000002_backfill_plan_module_from_plan_module_price.php.
 *
 * The columns that migration reads (is_free/trial_days on plan_module_price)
 * are DROPPED by migration 000003 in this same working tree, so this class
 * re-adds them on plan_module_price for the duration of EACH test method
 * only (setUp/tearDown), seeds the three pair shapes the backfill must
 * handle via the query builder (bypassing PlanModulePrice's $fillable, which
 * no longer includes either column), runs the migration's up() directly, and
 * asserts the copied plan_module rows. tearDown() drops the columns again so
 * no other Billing test ever observes them.
 */
final class PlanModuleBackfillMigrationTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('plan_module_price', function (Blueprint $table): void {
            $table->boolean('is_free')->default(false);
            $table->unsignedSmallInteger('trial_days')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::table('plan_module_price', function (Blueprint $table): void {
            $table->dropColumn(['is_free', 'trial_days']);
        });

        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_backfill_is_a_no_op_on_an_empty_table(): void
    {
        $this->assertSame(0, DB::table('plan_module_price')->count());

        $this->runBackfill();

        $this->assertSame(0, DB::table('plan_module')->count());
    }

    public function test_backfill_copies_flags_for_month_and_year_month_wins_on_conflict(): void
    {
        $plan = $this->makePlan('esencial');
        $monthYear = $this->makeBillableModule('month_year', 'Month+Year pair');
        $monthOnly = $this->makeBillableModule('month_only', 'Month-only pair');
        $yearOnly = $this->makeBillableModule('year_only', 'Year-only pair');

        // Pair 1: both intervals, DIFFERING flags — month must win the conflict
        // ('month' < 'year' alphabetically, mirrored from the pre-refactor
        // orderBy('interval')->first() trick).
        $this->rawPrice($plan, $monthYear, 'month', 0, true, 30);
        $this->rawPrice($plan, $monthYear, 'year', 95000, false, null);

        // Pair 2: month-only.
        $this->rawPrice($plan, $monthOnly, 'month', 12000, false, 7);

        // Pair 3: year-only.
        $this->rawPrice($plan, $yearOnly, 'year', 136800, true, null);

        // Precondition per the design's stated technique — empty plan_module.
        DB::table('plan_module')->delete();

        $this->runBackfill();

        $rows = DB::table('plan_module')->get()->keyBy(fn ($row) => $row->billable_module_id);

        $this->assertCount(3, $rows);

        $this->assertTrue((bool) $rows[$monthYear->id]->is_free, 'month row must win the conflict');
        $this->assertSame(30, $rows[$monthYear->id]->trial_days);

        $this->assertFalse((bool) $rows[$monthOnly->id]->is_free);
        $this->assertSame(7, $rows[$monthOnly->id]->trial_days);

        $this->assertTrue((bool) $rows[$yearOnly->id]->is_free);
        $this->assertNull($rows[$yearOnly->id]->trial_days);
    }

    private function runBackfill(): void
    {
        $migration = include database_path('../Modules/SubscriptionPlan/database/migrations/2026_07_22_000002_backfill_plan_module_from_plan_module_price.php');
        $migration->up();
    }

    /**
     * Insert a plan_module_price row via the query builder, bypassing
     * PlanModulePrice's $fillable (is_free/trial_days are no longer fillable
     * on that model in this working tree, but the COLUMNS exist for the
     * duration of this test method via setUp()'s Schema::table()).
     */
    private function rawPrice(BillingPackage $plan, BillableModule $module, string $interval, int $unitAmount, bool $isFree, ?int $trialDays): void
    {
        DB::table('plan_module_price')->insert([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $module->id,
            'interval' => $interval,
            'unit_amount' => $unitAmount,
            'currency' => 'MXN',
            'is_free' => $isFree,
            'is_active' => true,
            'trial_days' => $trialDays,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
