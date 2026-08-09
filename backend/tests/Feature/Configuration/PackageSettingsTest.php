<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for GET/PUT /config/package-settings (WU-3 / package-policies spec).
 *
 * The endpoint is implemented in ConfigurationController and reuses the
 * read.configuration / update.configuration abilities — no separate package_config
 * permission (per design §2 and tasks §32 flag note).
 *
 * Covers:
 *  - Exactly 7 package.* Setting rows seeded per tenant (TenantCatalogSeeder)
 *  - GET returns all 7 keys at default values after seeding
 *  - GET response is tenant-isolated (Tenant A's overrides don't leak to B)
 *  - GET with security role → 403 (no read.configuration)
 *  - PUT partial update: only touched slug changes; others remain at default
 *  - PUT cross-field constraint: reminder_after_days >= return_after_days → 422
 *  - PUT with security role → 403 (no update.configuration)
 */
final class PackageSettingsTest extends TestCase
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

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeRoleUser(Tenant $tenant, string $role): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    // =========================================================================
    // Seeding contract — exactly 7 package.* rows after TenantCreated
    // =========================================================================

    public function test_exactly_eight_package_settings_seeded_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $count = 0;
        $tenant->run(function () use (&$count): void {
            $count = Setting::where('slug', 'like', 'package.%')->count();
        });

        // 7 original slugs + require_pickup_code (Phase-2 completeness fix).
        $this->assertSame(8, $count, 'TenantCatalogSeeder must create exactly 8 package.* Setting rows');
    }

    // =========================================================================
    // GET /config/package-settings
    // =========================================================================

    public function test_admin_get_returns_all_eight_keys_at_defaults(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $data = $this->tenantRequest($this->token($admin, ['read.configuration']), $tenant)
            ->getJson('/api/config/package-settings')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('auto_notify_on_arrival', $data);
        $this->assertArrayHasKey('pickup_code_enabled', $data);
        $this->assertArrayHasKey('require_pickup_code', $data);
        $this->assertArrayHasKey('requires_pickup_signature', $data);
        $this->assertArrayHasKey('requires_pickup_evidence', $data);
        $this->assertArrayHasKey('reminder_after_days', $data);
        $this->assertArrayHasKey('return_after_days', $data);
        $this->assertArrayHasKey('liability_disclaimer', $data);

        // Default values per package-policies spec.
        $this->assertTrue($data['auto_notify_on_arrival']);
        $this->assertTrue($data['pickup_code_enabled']);
        $this->assertTrue($data['require_pickup_code'], 'require_pickup_code must default to true');
        $this->assertFalse($data['requires_pickup_signature']);
        $this->assertFalse($data['requires_pickup_evidence']);
        $this->assertSame(3, $data['reminder_after_days']);
        $this->assertSame(10, $data['return_after_days']);
        $this->assertNotEmpty($data['liability_disclaimer']);
    }

    public function test_get_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Override auto_notify_on_arrival to false in Tenant A.
        $tenantA->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.auto_notify_on_arrival'],
                [
                    'name' => 'package.auto_notify_on_arrival',
                    'slug' => 'package.auto_notify_on_arrival',
                    'value' => false,
                    'type' => Setting::TYPE_BOOLEAN,
                ]
            );
        });

        // Tenant B admin should still see the default true.
        $adminB = $this->makeRoleUser($tenantB, 'administrator');

        $data = $this->tenantRequest($this->token($adminB, ['read.configuration']), $tenantB)
            ->getJson('/api/config/package-settings')
            ->assertOk()
            ->json('data');

        $this->assertTrue(
            $data['auto_notify_on_arrival'],
            'Tenant B must not see Tenant A overridden value (auto_notify_on_arrival must be true)'
        );
    }

    public function test_security_role_get_returns_403(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        // Security role has read.package, create.package, update.package — NOT read.configuration.
        $this->tenantRequest($this->token($guard, ['read.package']), $tenant)
            ->getJson('/api/config/package-settings')
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /config/package-settings
    // =========================================================================

    public function test_partial_update_changes_only_the_sent_slug(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');
        $token = $this->token($admin, ['update.configuration', 'read.configuration']);

        // PUT only requires_pickup_signature.
        $response = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/package-settings', ['requires_pickup_signature' => true])
            ->assertOk()
            ->json('data');

        $this->assertTrue($response['requires_pickup_signature']);

        // Read back: the updated slug changed; the untouched ones remain at default.
        $data = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/package-settings')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['requires_pickup_signature'], 'Updated slug must persist');
        $this->assertTrue($data['auto_notify_on_arrival'], 'Untouched slug must stay at default');
        $this->assertSame(3, $data['reminder_after_days'], 'Untouched integer slug must stay at default');
    }

    public function test_cross_field_reminder_gte_return_days_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        // reminder_after_days (10) >= return_after_days (5) — must be rejected.
        $this->tenantRequest($this->token($admin, ['update.configuration']), $tenant)
            ->putJson('/api/config/package-settings', [
                'reminder_after_days' => 10,
                'return_after_days' => 5,
            ])
            ->assertUnprocessable();
    }

    public function test_cross_field_equal_days_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        // reminder_after_days == return_after_days — must also be rejected.
        $this->tenantRequest($this->token($admin, ['update.configuration']), $tenant)
            ->putJson('/api/config/package-settings', [
                'reminder_after_days' => 7,
                'return_after_days' => 7,
            ])
            ->assertUnprocessable();
    }

    public function test_valid_day_ordering_is_accepted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['update.configuration']), $tenant)
            ->putJson('/api/config/package-settings', [
                'reminder_after_days' => 5,
                'return_after_days' => 15,
            ])
            ->assertOk();
    }

    public function test_security_role_put_returns_403(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->putJson('/api/config/package-settings', ['requires_pickup_signature' => true])
            ->assertForbidden();
    }
}
