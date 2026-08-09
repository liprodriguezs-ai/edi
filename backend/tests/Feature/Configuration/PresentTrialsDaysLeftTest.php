<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Role as AppRole;
use App\Models\User;
use App\Services\SubscriptionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Configuration\Http\Controllers\ConfigurationController;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * trial-countdown-utility — presentTrials().days_left (the module-level trial
 * banner ConfigurationController::getSubscription() exposes) and the
 * narrative's cluster-level trial_days_left MUST agree for the same
 * tenant/entitlement/moment: one canonical calendar-days rule (CalendarDays),
 * never two independently-computed numbers for the same concept.
 *
 * presentTrials() is private and has no permission/tenancy dependency of its
 * own (TenantEntitlement is a CentralConnection model, explicitly scoped by
 * tenant_id — no BelongsToTenant global scope to satisfy), so it is invoked
 * directly via reflection, mirroring SubscriptionStateAccessTest's
 * subscriptionState() helper.
 */
final class PresentTrialsDaysLeftTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        parent::tearDown();
    }

    public function test_present_trials_days_left_agrees_with_the_narratives_trial_days_left(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'expires_at' => now()->addDays(30),
        ]);

        $trials = $this->presentTrials($tenant);
        $narrative = app(SubscriptionStateService::class)->narrativeFor($owner, $tenant);

        $this->assertCount(1, $trials);
        $this->assertSame('trial', $narrative['status']);
        $this->assertNotNull($trials[0]['days_left']);
        $this->assertSame($narrative['trial_days_left'], $trials[0]['days_left']);
    }

    /**
     * @return array<int, array{module_key: string, module_name: string, plan_key: string|null, plan_name: string|null, expires_at: string|null, days_left: int|null}>
     */
    private function presentTrials(Tenant $tenant): array
    {
        $method = new ReflectionMethod(ConfigurationController::class, 'presentTrials');
        $method->setAccessible(true);

        return $method->invoke(app(ConfigurationController::class), $tenant);
    }

    private function makeOwner(Tenant $tenant): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Owner Test',
            'email' => Str::random(10).'@test.com',
            'auth_active' => true,
        ]);

        AppRole::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
