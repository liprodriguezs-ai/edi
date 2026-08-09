<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Notification\Models\NotificationUser;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Verifies that billing:expire-manual-entitlements notifies the cluster
 * administrator when a SOURCE_TRIAL entitlement expires.
 *
 * Uses $connectionsToTransact = [] (no wrapping test transaction) so
 * DB::afterCommit inside the notification engine fires when each write commits.
 *
 * Data isolation: cleanBillingTables() in tearDown purges the central billing
 * tables that accumulate across transaction-less tests.
 */
final class TrialExpiredNotificationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGlobalRoles();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedGlobalRoles(): void
    {
        foreach (['president', 'treasurer', 'secretary', 'administrator', 'resident', 'renter', 'security'] as $slug) {
            Role::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function cleanBillingTables(): void
    {
        DB::table('system_tenant')->delete();
        DB::table('module_tenant')->delete();
        DB::table('subscription_payment')->delete();
        DB::table('tenant_entitlement')->delete();
        DB::table('plan_module_price')->delete();
        DB::table('billable_module_system_module')->delete();
        DB::table('billable_module')->delete();
        DB::table('billing_package')->delete();
    }

    private function makeCentralUser(string $name, ?string $roleSlug = null): User
    {
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('password'),
            'auth_active' => true,
        ]);

        if ($roleSlug !== null) {
            $user->assignRole($roleSlug);
        }

        return $user;
    }

    private function makeBillingPackage(string $key = 'esencial'): BillingPackage
    {
        return BillingPackage::create([
            'key' => $key,
            'name' => ucfirst($key),
            'description' => 'Plan '.$key,
            'sort_order' => 0,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => 1,
            'suggested_unit_max' => 100,
        ]);
    }

    private function makeBillableModule(string $key, string $name): BillableModule
    {
        return BillableModule::create([
            'key' => $key,
            'name' => $name,
            'description' => $name,
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => false,
        ]);
    }

    /**
     * Assert a NotificationUser row exists for the given user with a title that
     * contains the expected fragment (case-insensitive).
     * Must be called inside the tenant context.
     */
    private function assertUserNotified(string $userId, string $titleFragment): void
    {
        $exists = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', function ($q) use ($titleFragment): void {
                $q->where('title', 'like', '%'.$titleFragment.'%');
            })
            ->exists();

        $this->assertTrue(
            $exists,
            "Expected an in-app notification titled like '{$titleFragment}' for user {$userId}.",
        );
    }

    // =========================================================================
    // (a) Expired SOURCE_TRIAL → administrator receives in-app notification
    // =========================================================================

    public function test_cron_notifies_administrator_when_trial_expires(): void
    {
        // Arrange — tenant with full notification catalog seeded.
        $tenant = $this->makeTenant('Trial Notify Cluster');
        $this->seedTenant($tenant); // seeds CatalogSeeder (incl. trial_expired) + TenantDatabaseSeeder (incl. TenantNotificationSettingSeeder)

        $admin = $this->makeCentralUser('Admin Prueba', 'administrator');
        $admin->tenants()->attach($tenant->id);

        $plan = $this->makeBillingPackage('esencial');
        $billableModule = $this->makeBillableModule('package', 'Paquetería');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $billableModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addDays(14),
        ]);

        // Act — travel past the trial expiry and run the cron.
        $this->travelTo(now()->addDays(15));
        $this->artisan('billing:expire-manual-entitlements')->assertSuccessful();

        // Assert — the administrator user receives an in-app notification whose
        // title contains the module name (substituted from {module} in the fallback).
        $tenant->run(function () use ($admin): void {
            $this->assertUserNotified($admin->id, 'Paquetería');
        });
    }

    // =========================================================================
    // (b) Expired SOURCE_TRIAL while another entitlement keeps the cluster up →
    //     administrator is STILL notified (partial-lapse scenario)
    // =========================================================================

    public function test_administrator_is_notified_even_when_cluster_stays_active(): void
    {
        // Base plan (super_admin_free, perpetual) stays; addon TRIAL lapses.
        // The cluster remains active, but the admin must still be told the trial ended.
        $tenant = $this->makeTenant('Partial Lapse Notify Cluster');
        $this->seedTenant($tenant);

        $admin = $this->makeCentralUser('Admin Partial', 'administrator');
        $admin->tenants()->attach($tenant->id);

        $plan = $this->makeBillingPackage('esencial');
        $baseModule = $this->makeBillableModule('base', 'Plan Base');
        $addonModule = $this->makeBillableModule('package', 'Paquetería');

        // Perpetual base entitlement — keeps the cluster active.
        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $baseModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => null,
        ]);

        // Trial addon that expires in 14 days.
        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $addonModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addDays(14),
        ]);

        $this->travelTo(now()->addDays(15));
        $this->artisan('billing:expire-manual-entitlements')->assertSuccessful();

        // Cluster must stay active (base coverage remains).
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        // Administrator must still receive the trial-expired notification.
        $tenant->run(function () use ($admin): void {
            $this->assertUserNotified($admin->id, 'Paquetería');
        });
    }

    // =========================================================================
    // (c) Only SOURCE_TRIAL triggers the notification — SOURCE_MANUAL does NOT
    // =========================================================================

    public function test_cron_does_not_notify_for_expired_manual_source(): void
    {
        $tenant = $this->makeTenant('Manual No Notify Cluster');
        $this->seedTenant($tenant);

        $admin = $this->makeCentralUser('Admin Manual', 'administrator');
        $admin->tenants()->attach($tenant->id);

        $plan = $this->makeBillingPackage('esencial-manual');
        $billableModule = $this->makeBillableModule('base', 'Plan Manual');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $billableModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addDays(30),
        ]);

        $this->travelTo(now()->addDays(31));
        $this->artisan('billing:expire-manual-entitlements')->assertSuccessful();

        // No trial_expired notification must be created — source is manual, not trial.
        $tenant->run(function () use ($admin): void {
            $exists = NotificationUser::query()
                ->where('user_id', $admin->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%vencida%'))
                ->exists();

            $this->assertFalse(
                $exists,
                'A SOURCE_MANUAL expired entitlement must not trigger a trial_expired notification.',
            );
        });
    }
}
