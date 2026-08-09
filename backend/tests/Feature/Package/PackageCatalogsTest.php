<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Package\Models\PackageCarrier;
use Modules\Package\Models\PackageStatus;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the three Package catalog resources (WU-1 / package-catalogs spec):
 *
 *  - package_status: central FSM — read-only; writes return 405
 *  - package_carrier: tenant-scoped; admin creates, guard reads, delete blocked when packages exist
 *  - package_type: tenant-scoped; symmetric to carrier
 *
 * Each test is isolated via RefreshDatabase + WithTenantSetup.
 */
final class PackageCatalogsTest extends TestCase
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

    private function makeUser(Tenant $tenant, string $role): User
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
    // Package Status — central FSM catalog (read-only)
    // =========================================================================

    public function test_package_status_index_returns_five_seeded_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['read.package_status']), $tenant)
            ->getJson('/api/package_status')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    public function test_package_status_store_returns_405(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['create.package_status']), $tenant)
            ->postJson('/api/package_status', ['name' => 'Nuevo', 'key' => 'new', 'color' => '#FFF'])
            ->assertStatus(405);
    }

    public function test_package_status_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/package_status')
            ->assertUnauthorized();
    }

    // =========================================================================
    // Package Carrier — tenant-scoped catalog
    // =========================================================================

    public function test_admin_can_create_package_carrier(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['create.package_carrier']), $tenant)
            ->postJson('/api/package_carrier', ['name' => 'Redpack', 'order' => 8, 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Redpack');
    }

    public function test_security_can_read_package_carriers(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');

        $this->tenantRequest($this->token($security, ['read.package_carrier']), $tenant)
            ->getJson('/api/package_carrier')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    }

    public function test_carrier_creation_enforces_unique_name_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');
        $token = $this->token($admin, ['create.package_carrier']);

        // Create DHL first
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/package_carrier', ['name' => 'DHL duplicate'])
            ->assertCreated();

        // Attempt duplicate name
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/package_carrier', ['name' => 'DHL duplicate'])
            ->assertStatus(422);
    }

    public function test_carrier_index_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $adminA = $this->makeUser($tenantA, 'administrator');

        $tenantA->run(function (): void {
            PackageCarrier::create(['name' => 'OnlyInA', 'order' => 99, 'is_active' => true]);
        });

        $carriers = $this->tenantRequest($this->token($adminA, ['read.package_carrier']), $tenantA)
            ->getJson('/api/package_carrier')
            ->assertOk()
            ->json('data');

        $names = array_column($carriers, 'name');
        $this->assertContains('OnlyInA', $names);

        // Tenant B should never see Tenant A's carrier
        $adminB = $this->makeUser($tenantB, 'administrator');
        $carriersB = $this->tenantRequest($this->token($adminB, ['read.package_carrier']), $tenantB)
            ->getJson('/api/package_carrier')
            ->assertOk()
            ->json('data');

        $this->assertNotContains('OnlyInA', array_column($carriersB, 'name'));
    }

    // =========================================================================
    // Package Type — tenant-scoped catalog (mirrors carrier)
    // =========================================================================

    public function test_admin_can_create_package_type(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['create.package_type']), $tenant)
            ->postJson('/api/package_type', ['name' => 'Pallet', 'order' => 5, 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pallet');
    }

    public function test_security_can_read_package_types(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');

        $this->tenantRequest($this->token($security, ['read.package_type']), $tenant)
            ->getJson('/api/package_type')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    }

    public function test_package_status_constants_match_seeded_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');

        // All five UUID constants must point to existing rows.
        $uuids = [
            PackageStatus::RECEIVED,
            PackageStatus::READY,
            PackageStatus::PICKED_UP,
            PackageStatus::RETURNED,
            PackageStatus::INCIDENT,
        ];

        foreach ($uuids as $uuid) {
            $this->tenantRequest($this->token($admin, ['read.package_status']), $tenant)
                ->getJson("/api/package_status/{$uuid}")
                ->assertOk();
        }
    }
}
