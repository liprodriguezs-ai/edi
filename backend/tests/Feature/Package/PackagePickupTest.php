<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;
use Modules\Package\Services\PackageService;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for Package pickup & custody endpoints (WU-2 / package-pickup spec):
 *
 *  - pickup_code hidden from guard index; visible to unit member via my-packages
 *  - verify-code flow (valid / unknown / terminal / resident blocked)
 *  - handover atomicity and policy enforcement
 *  - my-packages staff exclusion and tenant isolation
 *  - pickup_code uniqueness within tenant; same value allowed across tenants
 */
final class PackagePickupTest extends TestCase
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

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        return $unit;
    }

    private function linkToUnit(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
        });
    }

    /**
     * Register a single package in the tenant context using the service directly
     * (avoids going through HTTP for setup, speed-optimised).
     */
    private function registerPackage(Tenant $tenant, Unit $unit, User $guard): Package
    {
        $pkg = null;
        $tenant->run(function () use ($unit, $guard, &$pkg): void {
            $pkg = app(PackageService::class)->register([
                'unit_id' => $unit->id,
            ], $guard);
        });

        return $pkg;
    }

    // =========================================================================
    // pickup_code visibility
    // =========================================================================

    public function test_pickup_code_absent_from_guard_index_response(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $this->registerPackage($tenant, $unit, $guard);

        $data = $this->tenantRequest($this->token($guard, ['create.package', 'read.package']), $tenant)
            ->getJson('/api/package')
            ->assertOk()
            ->json('data');

        foreach ($data as $pkg) {
            $this->assertArrayNotHasKey('pickup_code', $pkg);
        }
    }

    public function test_resident_my_packages_returns_pickup_code_in_xxxx_format(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);

        $this->linkToUnit($tenant, $unit, $resident);
        $this->registerPackage($tenant, $unit, $guard);

        $data = $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson('/api/my-packages')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $pkg) {
            $this->assertArrayHasKey('pickup_code', $pkg);
            if ($pkg['pickup_code'] !== null) {
                $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $pkg['pickup_code']);
            }
        }
    }

    public function test_resident_on_two_units_sees_packages_from_both(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);

        $this->linkToUnit($tenant, $unitA, $resident);
        $this->linkToUnit($tenant, $unitB, $resident);

        $this->registerPackage($tenant, $unitA, $guard);
        $this->registerPackage($tenant, $unitA, $guard);
        $this->registerPackage($tenant, $unitB, $guard);

        $data = $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson('/api/my-packages')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $data);
    }

    public function test_guard_cannot_call_my_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        // Token includes create.package — triggers the staff exclusion guard.
        $this->tenantRequest($this->token($guard, ['create.package', 'read.package']), $tenant)
            ->getJson('/api/my-packages')
            ->assertForbidden();
    }

    public function test_my_packages_tenant_isolation(): void
    {
        $tenant = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenant);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenant, 'security');
        $residentA = $this->makeRoleUser($tenant, 'resident');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenantB);

        $this->linkToUnit($tenant, $unitA, $residentA);

        // Register a package in TENANT B
        $guardB = $this->makeRoleUser($tenantB, 'security');
        $this->registerPackage($tenantB, $unitB, $guardB);

        // Register a package in tenant A
        $this->registerPackage($tenant, $unitA, $guardA);

        // Resident A sees only tenant A packages
        $data = $this->tenantRequest($this->token($residentA, ['read.package']), $tenant)
            ->getJson('/api/my-packages')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
    }

    // =========================================================================
    // verify-code
    // =========================================================================

    public function test_verify_code_returns_all_ready_packages_for_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);

        // Create 3 READY packages
        $pkg1 = $this->registerPackage($tenant, $unit, $guard);
        $this->registerPackage($tenant, $unit, $guard);
        $this->registerPackage($tenant, $unit, $guard);

        // Create 1 package then pick it up (terminal)
        $pkgPickedUp = $this->registerPackage($tenant, $unit, $guard);
        $tenant->run(function () use ($pkgPickedUp): void {
            $pkgPickedUp->package_status_id = PackageStatus::PICKED_UP;
            $pkgPickedUp->saveQuietly();
        });

        // Read pickup_code from DB (it's hidden in the serialized model)
        $code = null;
        $tenant->run(function () use ($pkg1, &$code): void {
            $code = Package::find($pkg1->id)?->pickup_code;
        });

        $this->assertNotNull($code, 'Package should have a pickup_code when pickup_code_enabled=true');

        $response = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/verify-code', ['pickup_code' => $code])
            ->assertOk()
            ->json();

        $this->assertTrue($response['valid']);
        // Only 3 READY packages, not the picked_up one
        $this->assertCount(3, $response['packages']);
    }

    public function test_verify_code_response_does_not_include_pickup_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $code = null;
        $tenant->run(function () use ($pkg, &$code): void {
            $code = Package::find($pkg->id)?->pickup_code;
        });

        $this->assertNotNull($code);

        $response = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/verify-code', ['pickup_code' => $code])
            ->assertOk()
            ->json();

        foreach ($response['packages'] as $p) {
            $this->assertArrayNotHasKey('pickup_code', $p);
        }
    }

    public function test_verify_code_returns_404_for_unknown_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/verify-code', ['pickup_code' => 'ZZZZ-9999'])
            ->assertNotFound();
    }

    public function test_verify_code_returns_422_for_already_picked_up_package(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $code = null;
        $tenant->run(function () use ($pkg, &$code): void {
            $code = Package::find($pkg->id)?->pickup_code;
            $pkg->package_status_id = PackageStatus::PICKED_UP;
            $pkg->saveQuietly();
        });

        $this->assertNotNull($code);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/verify-code', ['pickup_code' => $code])
            ->assertStatus(422);
    }

    public function test_resident_cannot_call_verify_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeRoleUser($tenant, 'resident');

        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->postJson('/api/package/verify-code', ['pickup_code' => 'ABCD-1234'])
            ->assertForbidden();
    }

    /**
     * Regression: scanning a Tenant-B pickup code from Tenant-A context must 404.
     * The BelongsToTenant global scope on the where('pickup_code', ...) query enforces
     * this; the test guards against a future refactor accidentally dropping the scope.
     */
    public function test_verify_code_does_not_return_cross_tenant_package(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenantA, 'security');
        $guardB = $this->makeRoleUser($tenantB, 'security');
        $unitB = $this->makeUnit($tenantB);
        $pkgB = $this->registerPackage($tenantB, $unitB, $guardB);

        $codeB = null;
        $tenantB->run(function () use ($pkgB, &$codeB): void {
            $codeB = Package::find($pkgB->id)?->pickup_code;
        });

        $this->assertNotNull($codeB);

        $this->tenantRequest($this->token($guardA, ['update.package']), $tenantA)
            ->postJson('/api/package/verify-code', ['pickup_code' => $codeB])
            ->assertNotFound();
    }

    // =========================================================================
    // pickup-policy (BUG-1 fix)
    // =========================================================================

    /**
     * Guards (update.package) must be able to fetch the two pickup-policy booleans
     * without holding read.configuration. The response must include both keys.
     */
    public function test_guard_gets_pickup_policy_with_both_keys(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        $data = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->getJson('/api/package/pickup-policy')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('requires_pickup_signature', $data);
        $this->assertArrayHasKey('requires_pickup_evidence', $data);
        $this->assertIsBool($data['requires_pickup_signature']);
        $this->assertIsBool($data['requires_pickup_evidence']);
    }

    /**
     * Residents hold read.package but NOT update.package — they must receive 403
     * so the endpoint is never callable from resident context.
     */
    public function test_resident_cannot_get_pickup_policy(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeRoleUser($tenant, 'resident');

        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson('/api/package/pickup-policy')
            ->assertForbidden();
    }

    /**
     * Overriding a setting in Tenant A must not affect Tenant B's pickup-policy response.
     */
    public function test_pickup_policy_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Enable requires_pickup_signature in Tenant A only.
        $tenantA->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.requires_pickup_signature', 'tenant_id' => tenant('id')],
                ['value' => true]
            );
        });

        $guardB = $this->makeRoleUser($tenantB, 'security');

        $data = $this->tenantRequest($this->token($guardB, ['update.package']), $tenantB)
            ->getJson('/api/package/pickup-policy')
            ->assertOk()
            ->json();

        // Tenant B must still see the default (false); it did not inherit Tenant A's override.
        $this->assertFalse($data['requires_pickup_signature'],
            'Tenant B must not inherit requires_pickup_signature from Tenant A');
    }

    // =========================================================================
    // handover
    // =========================================================================

    public function test_handover_two_packages_sets_both_to_picked_up(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Disable require_pickup_code so we can test proxy-name behavior without
        // providing a QR code (code enforcement is covered by its own test group).
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.require_pickup_code', 'tenant_id' => tenant('id')],
                ['value' => false]
            );
        });

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg1 = $this->registerPackage($tenant, $unit, $guard);
        $pkg2 = $this->registerPackage($tenant, $unit, $guard);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkg1->id, $pkg2->id],
                'pickup_proxy_name' => 'María López',
                'pickup_proxy_relationship' => 'Esposa',
            ])
            ->assertOk();

        $tenant->run(function () use ($pkg1, $pkg2): void {
            $fresh1 = Package::findOrFail($pkg1->id);
            $fresh2 = Package::findOrFail($pkg2->id);

            $this->assertSame(PackageStatus::PICKED_UP, $fresh1->package_status_id);
            $this->assertSame(PackageStatus::PICKED_UP, $fresh2->package_status_id);
            $this->assertNotNull($fresh1->picked_up_at);
            $this->assertNotNull($fresh2->picked_up_at);
            $this->assertSame('María López', $fresh1->pickup_proxy_name);
            $this->assertSame('María López', $fresh2->pickup_proxy_name);
            $this->assertSame('Esposa', $fresh1->pickup_proxy_relationship);
        });
    }

    public function test_handover_rejected_when_signature_required_but_missing(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Enable requires_pickup_signature
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.requires_pickup_signature', 'tenant_id' => tenant('id')],
                ['value' => true]
            );
        });

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkg->id],
            ])
            ->assertStatus(422);

        // Package remains READY
        $tenant->run(function () use ($pkg): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkg->id)->package_status_id);
        });
    }

    public function test_handover_rolls_back_when_one_package_is_already_picked_up(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Disable require_pickup_code so the atomicity scenario is tested in isolation
        // (code-enforcement is covered by the require_pickup_code test group).
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.require_pickup_code', 'tenant_id' => tenant('id')],
                ['value' => false]
            );
        });

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkgReady = $this->registerPackage($tenant, $unit, $guard);
        $pkgDone = $this->registerPackage($tenant, $unit, $guard);

        // Mark pkgDone as already picked up
        $tenant->run(function () use ($pkgDone): void {
            $pkgDone->package_status_id = PackageStatus::PICKED_UP;
            $pkgDone->saveQuietly();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkgReady->id, $pkgDone->id],
            ])
            ->assertStatus(422);

        // pkgReady must still be READY (transaction rolled back)
        $tenant->run(function () use ($pkgReady): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkgReady->id)->package_status_id);
        });
    }

    // =========================================================================
    // pickup_code uniqueness
    // =========================================================================

    public function test_ten_packages_all_have_unique_pickup_codes(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);

        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $pkg = $this->registerPackage($tenant, $unit, $guard);
            $tenant->run(function () use ($pkg, &$codes): void {
                $codes[] = Package::find($pkg->id)?->pickup_code;
            });
        }

        $uniqueCodes = array_unique(array_filter($codes));
        $this->assertCount(10, $uniqueCodes, 'All 10 pickup codes must be unique within the tenant');
    }

    public function test_same_pickup_code_in_different_tenants_does_not_violate_constraint(): void
    {
        // We can't guarantee collision, but we CAN verify that both tenants can
        // register packages independently without constraint violations — and
        // that the partial unique index is per-tenant, not global.
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenantA, 'security');
        $guardB = $this->makeRoleUser($tenantB, 'security');
        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);

        // Register packages in both tenants — no constraint violation expected.
        $pkgA = $this->registerPackage($tenantA, $unitA, $guardA);
        $pkgB = $this->registerPackage($tenantB, $unitB, $guardB);

        $codeA = null;
        $codeB = null;
        // Arrow functions do NOT capture outer variables by reference; use explicit closures.
        $tenantA->run(function () use ($pkgA, &$codeA): void {
            $codeA = Package::find($pkgA->id)?->pickup_code;
        });
        $tenantB->run(function () use ($pkgB, &$codeB): void {
            $codeB = Package::find($pkgB->id)?->pickup_code;
        });

        // Both codes were generated without throwing — isolation confirmed.
        $this->assertNotNull($codeA);
        $this->assertNotNull($codeB);
    }

    // =========================================================================
    // pickup-policy — require_pickup_code (Phase-2 fix)
    // =========================================================================

    public function test_pickup_policy_includes_require_pickup_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');

        $data = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->getJson('/api/package/pickup-policy')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('require_pickup_code', $data);
        $this->assertIsBool($data['require_pickup_code']);
        // Default is true per TenantCatalogSeeder.
        $this->assertTrue($data['require_pickup_code']);
    }

    // =========================================================================
    // handover — require_pickup_code enforcement (Phase-2 fix)
    // =========================================================================

    /**
     * When require_pickup_code is ON (default), handover without pickup_code → 422.
     * The packages must remain READY.
     */
    public function test_handover_rejected_when_require_pickup_code_on_and_no_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        // require_pickup_code is true by default after seedTenant.
        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', ['package_ids' => [$pkg->id]])
            ->assertStatus(422);

        $tenant->run(function () use ($pkg): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkg->id)->package_status_id);
        });
    }

    /**
     * When require_pickup_code is ON, a code that does not resolve to the packages' unit → 422.
     */
    public function test_handover_rejected_when_code_resolves_to_different_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);

        $pkgA = $this->registerPackage($tenant, $unitA, $guard);
        $pkgB = $this->registerPackage($tenant, $unitB, $guard);

        // Fetch unit B's code (for unit B's package) but try to hand over unit A's package.
        $codeB = null;
        $tenant->run(function () use ($pkgB, &$codeB): void {
            $codeB = Package::find($pkgB->id)?->pickup_code;
        });

        $this->assertNotNull($codeB);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkgA->id],
                'pickup_code' => $codeB,
            ])
            ->assertStatus(422);

        // pkgA must remain READY.
        $tenant->run(function () use ($pkgA): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkgA->id)->package_status_id);
        });
    }

    /**
     * When require_pickup_code is ON, mixing packages from two units in one handover → 422,
     * even if a valid code is supplied.
     */
    public function test_handover_rejected_when_package_ids_span_multiple_units(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);

        $pkgA = $this->registerPackage($tenant, $unitA, $guard);
        $pkgB = $this->registerPackage($tenant, $unitB, $guard);

        $codeA = null;
        $tenant->run(function () use ($pkgA, &$codeA): void {
            $codeA = Package::find($pkgA->id)?->pickup_code;
        });

        $this->assertNotNull($codeA);

        // Both pkgA (unit A) and pkgB (unit B) in same handover → 422.
        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkgA->id, $pkgB->id],
                'pickup_code' => $codeA,
            ])
            ->assertStatus(422);
    }

    /**
     * When require_pickup_code is OFF, handover without a code must succeed.
     */
    public function test_handover_allowed_without_code_when_setting_off(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Disable require_pickup_code.
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.require_pickup_code', 'tenant_id' => tenant('id')],
                ['value' => false]
            );
        });

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', ['package_ids' => [$pkg->id]])
            ->assertOk();

        $tenant->run(function () use ($pkg): void {
            $this->assertSame(PackageStatus::PICKED_UP, Package::findOrFail($pkg->id)->package_status_id);
        });
    }

    /**
     * When require_pickup_code is ON and the correct code is supplied, handover succeeds.
     */
    public function test_handover_succeeds_with_correct_pickup_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $code = null;
        $tenant->run(function () use ($pkg, &$code): void {
            $code = Package::find($pkg->id)?->pickup_code;
        });

        $this->assertNotNull($code);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkg->id],
                'pickup_code' => $code,
            ])
            ->assertOk();

        $tenant->run(function () use ($pkg): void {
            $this->assertSame(PackageStatus::PICKED_UP, Package::findOrFail($pkg->id)->package_status_id);
        });
    }

    // =========================================================================
    // handover — stale code & concurrent re-check guard (plan 054)
    // =========================================================================

    /**
     * Regression: a terminal (PICKED_UP) package's pickup_code must not satisfy the
     * code gate for a DIFFERENT, still-READY package of the same unit. Codes are
     * never rotated on pickup, so pre-fix the code lookup (no status filter) would
     * resolve the stale code and let the handover through. Post-fix the lookup
     * filters package_status_id = READY, so the stale code resolves to nothing.
     */
    public function test_handover_rejected_with_code_from_terminal_package(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);

        $pkgPickedUp = $this->registerPackage($tenant, $unit, $guard);
        $pkgReady = $this->registerPackage($tenant, $unit, $guard);

        $staleCode = null;
        $tenant->run(function () use ($pkgPickedUp, &$staleCode): void {
            $staleCode = Package::find($pkgPickedUp->id)?->pickup_code;
            $pkgPickedUp->package_status_id = PackageStatus::PICKED_UP;
            $pkgPickedUp->saveQuietly();
        });

        $this->assertNotNull($staleCode, 'The terminal package must have a pickup_code to exercise the stale-code path');

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkgReady->id],
                'pickup_code' => $staleCode,
            ])
            ->assertStatus(422);

        // pkgReady must remain READY — the stale code must not unlock it.
        $tenant->run(function () use ($pkgReady): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkgReady->id)->package_status_id);
        });
    }

    /**
     * Happy-path through the pickup-code gate must still work after the READY
     * filter was added to the code lookup: a valid READY package + its own code
     * succeeds, transitions to PICKED_UP, and custody fields (who collected it,
     * plus any proxy info) are persisted.
     */
    public function test_handover_with_valid_code_succeeds_and_records_custody(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $code = null;
        $tenant->run(function () use ($pkg, &$code): void {
            $code = Package::find($pkg->id)?->pickup_code;
        });

        $this->assertNotNull($code);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkg->id],
                'pickup_code' => $code,
                'pickup_proxy_name' => 'Juan Pérez',
                'pickup_proxy_relationship' => 'Hermano',
            ])
            ->assertOk();

        $tenant->run(function () use ($pkg, $guard): void {
            $fresh = Package::findOrFail($pkg->id);
            $this->assertSame(PackageStatus::PICKED_UP, $fresh->package_status_id);
            $this->assertNotNull($fresh->picked_up_at);
            $this->assertSame($guard->id, $fresh->picked_up_by_user_id);
            $this->assertSame('Juan Pérez', $fresh->pickup_proxy_name);
            $this->assertSame('Hermano', $fresh->pickup_proxy_relationship);
        });
    }

    /**
     * Sequential proxy for the concurrency re-check added by lockForUpdate(): the
     * test harness cannot fire two truly-parallel requests, but it CAN assert the
     * outcome the lock guarantees under a real race — a second handover attempt on
     * a package that is no longer READY must 422, never re-apply picked_up_* over
     * the first (committed) custody record.
     */
    public function test_second_handover_of_already_picked_up_package_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Disable require_pickup_code to isolate the re-check from the code gate.
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.require_pickup_code', 'tenant_id' => tenant('id')],
                ['value' => false]
            );
        });

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', ['package_ids' => [$pkg->id]])
            ->assertOk();

        // Second handover of the SAME (now PICKED_UP) package must be rejected.
        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson('/api/package/handover', ['package_ids' => [$pkg->id]])
            ->assertStatus(422);

        $tenant->run(function () use ($pkg): void {
            $this->assertSame(PackageStatus::PICKED_UP, Package::findOrFail($pkg->id)->package_status_id);
        });
    }

    // =========================================================================
    // /my-packages?history=1 (Phase-2 fix)
    // =========================================================================

    public function test_my_packages_history_returns_terminal_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);

        $this->linkToUnit($tenant, $unit, $resident);

        // Register 2 packages and hand them over so they are PICKED_UP.
        $pkg1 = $this->registerPackage($tenant, $unit, $guard);
        $pkg2 = $this->registerPackage($tenant, $unit, $guard);
        $pkgReady = $this->registerPackage($tenant, $unit, $guard); // stays READY

        // Disable require_pickup_code for simple handover in tests.
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'package.require_pickup_code', 'tenant_id' => tenant('id')],
                ['value' => false]
            );
        });

        $guardToken = $this->token($guard, ['update.package']);
        $this->tenantRequest($guardToken, $tenant)->postJson('/api/package/handover', ['package_ids' => [$pkg1->id]])->assertOk();
        $this->tenantRequest($guardToken, $tenant)->postJson('/api/package/handover', ['package_ids' => [$pkg2->id]])->assertOk();

        // History — must return only terminal packages (2), not the READY one.
        $residentToken = $this->token($resident, ['read.package']);
        $data = $this->tenantRequest($residentToken, $tenant)
            ->getJson('/api/my-packages?history=1')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data, 'History must return exactly the 2 terminal packages');

        // Without history — must return only the READY one.
        $pending = $this->tenantRequest($residentToken, $tenant)
            ->getJson('/api/my-packages')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $pending, 'Default my-packages must return only the non-terminal package');
    }

    public function test_my_packages_history_does_not_expose_pickup_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);

        $this->linkToUnit($tenant, $unit, $resident);

        $pkg = $this->registerPackage($tenant, $unit, $guard);

        // Mark terminal directly to skip handover complexity.
        $tenant->run(function () use ($pkg): void {
            $pkg->package_status_id = PackageStatus::RETURNED;
            $pkg->saveQuietly();
        });

        $data = $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson('/api/my-packages?history=1')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertArrayNotHasKey('pickup_code', $item, 'pickup_code must not appear on history items');
        }
    }
}
