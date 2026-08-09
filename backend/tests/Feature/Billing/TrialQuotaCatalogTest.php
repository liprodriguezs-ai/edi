<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Database\Seeders\PlanQuotaSeeder;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\QuotaService;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for the plan-quota-catalog capability
 * (openspec/changes/trial-usage-quotas/specs/plan-quota-catalog/spec.md).
 * Covers REQ-QC-01 through REQ-QC-05.
 */
final class TrialQuotaCatalogTest extends TestCase
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

        // plan_quota is CENTRAL — clean between tests (see QuotaServiceTest).
        DB::table('plan_quota')->delete();

        parent::tearDown();
    }

    // =========================================================================
    // REQ-QC-01 — a quota row expresses a trial cap
    // =========================================================================

    public function test_a_global_trial_row_resolves_its_limit(): void
    {
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_VISITOR,
            'limit_value' => 5,
        ]);

        $tenant = $this->makeTenant();

        $limit = $tenant->run(fn () => app(QuotaService::class)->resolveQuota(PlanQuota::RESOURCE_VISITOR, PlanQuota::SCOPE_TRIAL));

        $this->assertSame(5, $limit);
    }

    // =========================================================================
    // REQ-QC-02 — most-specific row wins
    // =========================================================================

    public function test_plan_specific_row_overrides_global_and_other_plans_keep_the_global(): void
    {
        $premium = BillingPackage::create([
            'key' => 'premium_'.Str::random(6),
            'name' => 'Premium',
            'sort_order' => 0,
            'is_active' => true,
            'is_public' => true,
        ]);

        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_AMENITY_RESERVATION,
            'limit_value' => 3,
        ]);
        PlanQuota::create([
            'billing_package_id' => $premium->id,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_AMENITY_RESERVATION,
            'limit_value' => 10,
        ]);

        $premiumTenant = $this->makeTenant();
        $otherTenant = $this->makeTenant();

        $premiumLimit = $premiumTenant->run(function () use ($premium): ?int {
            TenantEntitlement::create([
                'tenant_id' => tenant('id'),
                'billable_module_id' => $this->makeBillableModule()->id,
                'billing_package_id' => $premium->id,
                'source' => TenantEntitlement::SOURCE_TRIAL,
                'expires_at' => now()->addDays(14),
            ]);

            return app(QuotaService::class)->resolveQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, PlanQuota::SCOPE_TRIAL);
        });

        $otherLimit = $otherTenant->run(fn () => app(QuotaService::class)->resolveQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, PlanQuota::SCOPE_TRIAL));

        $this->assertSame(10, $premiumLimit, 'A tenant on Premium must see the plan-specific row');
        $this->assertSame(3, $otherLimit, 'A tenant on any other plan must see the global row');
    }

    // =========================================================================
    // REQ-QC-03 — -1 never blocks
    // =========================================================================

    public function test_unlimited_never_blocks_at_100000_consumed(): void
    {
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_VISITOR,
            'limit_value' => PlanQuota::UNLIMITED,
        ]);

        $tenant = $this->makeTenant();

        $check = $tenant->run(fn () => app(QuotaService::class)->checkConsumption(PlanQuota::RESOURCE_VISITOR, 100_000));

        $this->assertTrue($check->allowed);
    }

    // =========================================================================
    // REQ-QC-04 — duplicate global rows rejected at the DB level
    // =========================================================================

    public function test_a_second_global_row_for_the_same_key_is_rejected_by_the_database(): void
    {
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_VISITOR,
            'limit_value' => 5,
        ]);

        $this->expectException(QueryException::class);

        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_VISITOR,
            'limit_value' => 7,
        ]);
    }

    // =========================================================================
    // REQ-QC-05 — shipped seed + idempotent reruns
    // =========================================================================

    public function test_seeder_ships_exactly_the_three_v1_resources_at_unlimited(): void
    {
        (new PlanQuotaSeeder)->run();

        $rows = PlanQuota::query()->whereNull('billing_package_id')->where('scope', PlanQuota::SCOPE_TRIAL)->get();

        $this->assertCount(3, $rows);
        foreach (PlanQuota::RESOURCES as $resourceKey) {
            $row = $rows->firstWhere('resource_key', $resourceKey);
            $this->assertNotNull($row, "Missing shipped row for {$resourceKey}");
            $this->assertSame(PlanQuota::UNLIMITED, $row->limit_value);
        }
    }

    public function test_rerunning_the_seeder_preserves_an_operator_edited_value(): void
    {
        (new PlanQuotaSeeder)->run();

        $row = PlanQuota::query()
            ->whereNull('billing_package_id')
            ->where('scope', PlanQuota::SCOPE_TRIAL)
            ->where('resource_key', PlanQuota::RESOURCE_VISITOR)
            ->firstOrFail();
        $row->limit_value = 5;
        $row->save();

        Artisan::call('db:seed', ['--class' => PlanQuotaSeeder::class, '--force' => true]);

        $row->refresh();

        $this->assertSame(5, $row->limit_value, 'A rerun must NOT overwrite an operator-tuned limit_value');
        $this->assertSame(
            3,
            PlanQuota::query()->whereNull('billing_package_id')->where('scope', PlanQuota::SCOPE_TRIAL)->count(),
            'A rerun must not create a duplicate row'
        );
    }

    private function makeBillableModule(): BillableModule
    {
        return BillableModule::create([
            'key' => 'base_'.Str::random(8),
            'name' => 'Base',
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => true,
        ]);
    }
}
