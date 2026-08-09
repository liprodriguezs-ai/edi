<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantQuotaUsage;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature test for the quota-visibility capability's backend half (REQ-QV-01)
 * (openspec/changes/trial-usage-quotas/specs/quota-visibility/spec.md).
 * REQ-QV-02 (frontend interception) is covered by apiClient.ts's own suite
 * (BUSINESS_CODES wiring, no new interceptor branch per D9).
 */
final class TrialQuotaVisibilityTest extends TestCase
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

        DB::table('plan_quota')->delete();

        parent::tearDown();
    }

    public function test_config_subscription_reports_visitor_quota_limit_and_usage(): void
    {
        $tenant = $this->makeTenant('Visibility Cluster');
        $this->seedTenant($tenant);

        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_VISITOR,
            'limit_value' => 5,
        ]);
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_AMENITY_RESERVATION,
            'limit_value' => PlanQuota::UNLIMITED,
        ]);

        $this->inTenant($tenant, function (): void {
            TenantQuotaUsage::create(['resource_key' => PlanQuota::RESOURCE_VISITOR, 'used_count' => 3]);
        });

        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200);

        $quotas = collect($response->json('data.quotas'));

        $visitorQuota = $quotas->firstWhere('resource', PlanQuota::RESOURCE_VISITOR);
        $this->assertNotNull($visitorQuota);
        $this->assertSame(5, $visitorQuota['limit']);
        $this->assertSame(3, $visitorQuota['used']);

        $unlimitedQuota = $quotas->firstWhere('resource', PlanQuota::RESOURCE_AMENITY_RESERVATION);
        $this->assertNotNull($unlimitedQuota);
        $this->assertNull($unlimitedQuota['limit'], 'Unlimited MUST be represented as null, never a fake large number');
    }
}
