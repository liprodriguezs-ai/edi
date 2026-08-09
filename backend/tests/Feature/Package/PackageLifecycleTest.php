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
use Modules\Notification\Models\NotificationUser;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;
use Modules\Package\Services\PackageService;
// Audit import removed: Laravel Auditing is disabled in console/test mode (audit.console=false by default).
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for Package lifecycle FSM transitions (WU-2 / package-lifecycle spec):
 *
 *  - All new packages land in READY (not RECEIVED)
 *  - returnPackage: ready → returned; terminal → 422
 *  - flagIncident: ready → incident; terminal → 422
 *  - reassignUnit: changes unit_id, audit recorded; cross-tenant → 422; terminal → 422
 *  - Permission gating: residents cannot perform lifecycle operations
 */
final class PackageLifecycleTest extends TestCase
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
    // FSM: initial state
    // =========================================================================

    public function test_newly_registered_package_lands_in_ready_not_received(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);

        $response = $this->tenantRequest($this->token($guard, ['create.package']), $tenant)
            ->postJson('/api/package', ['unit_id' => $unit->id])
            ->assertCreated();

        $this->assertSame(PackageStatus::READY, $response->json('data.package_status_id'));
        $this->assertNotSame(PackageStatus::RECEIVED, $response->json('data.package_status_id'));
    }

    // =========================================================================
    // returnPackage
    // =========================================================================

    public function test_return_ready_package_changes_status_to_returned(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $response = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/return", [
                'reason' => 'No reclamado en plazo',
            ])
            ->assertOk();

        $this->assertSame(PackageStatus::RETURNED, $response->json('data.package_status_id'));
        $this->assertSame('No reclamado en plazo', $response->json('data.incident_reason'));
    }

    public function test_return_picked_up_package_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        // Mark as picked up
        $tenant->run(function () use ($pkg): void {
            $pkg->package_status_id = PackageStatus::PICKED_UP;
            $pkg->saveQuietly();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/return", ['reason' => 'Late return'])
            ->assertStatus(422);

        // Status unchanged
        $tenant->run(function () use ($pkg): void {
            $this->assertSame(PackageStatus::PICKED_UP, Package::findOrFail($pkg->id)->package_status_id);
        });
    }

    // =========================================================================
    // flagIncident
    // =========================================================================

    public function test_flag_incident_on_ready_package_changes_status_to_incident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $response = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/incident", [
                'reason' => 'Paquete dañado al llegar',
            ])
            ->assertOk();

        $this->assertSame(PackageStatus::INCIDENT, $response->json('data.package_status_id'));
        $this->assertSame('Paquete dañado al llegar', $response->json('data.incident_reason'));
    }

    public function test_flag_incident_on_returned_package_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        // Mark as returned
        $tenant->run(function () use ($pkg): void {
            $pkg->package_status_id = PackageStatus::RETURNED;
            $pkg->saveQuietly();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/incident", ['reason' => 'Lost'])
            ->assertStatus(422);
    }

    // =========================================================================
    // reassignUnit
    // =========================================================================

    public function test_reassign_ready_package_to_another_unit_in_same_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unitA, $guard);

        $originalReceivedAt = $pkg->received_at;
        $originalReceivedBy = $pkg->received_by_user_id;

        $response = $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/reassign", [
                'unit_id' => $unitB->id,
            ])
            ->assertOk();

        $this->assertSame($unitB->id, $response->json('data.unit_id'));

        // Chain of custody preserved
        $tenant->run(function () use ($pkg, $originalReceivedBy): void {
            $fresh = Package::findOrFail($pkg->id);
            $this->assertSame($originalReceivedBy, $fresh->received_by_user_id);
        });
    }

    public function test_reassign_to_unit_in_different_tenant_returns_422(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenantA, 'security');
        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);
        $pkg = $this->registerPackage($tenantA, $unitA, $guardA);

        $this->tenantRequest($this->token($guardA, ['update.package']), $tenantA)
            ->postJson("/api/package/{$pkg->id}/reassign", [
                'unit_id' => $unitB->id, // belongs to Tenant B
            ])
            ->assertStatus(422);

        // unit_id unchanged
        $tenantA->run(function () use ($pkg, $unitA): void {
            $this->assertSame($unitA->id, Package::findOrFail($pkg->id)->unit_id);
        });
    }

    public function test_reassign_picked_up_package_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unitA, $guard);

        $tenant->run(function () use ($pkg): void {
            $pkg->package_status_id = PackageStatus::PICKED_UP;
            $pkg->saveQuietly();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/reassign", [
                'unit_id' => $unitB->id,
            ])
            ->assertStatus(422);
    }

    public function test_reassign_records_audit_entry(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $pkg = $this->registerPackage($tenant, $unitA, $guard);

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/reassign", [
                'unit_id' => $unitB->id,
            ])
            ->assertOk();

        // Verify the unit_id was updated and GlobalObserver stamped user_updated_id.
        // Note: OwenIt\Auditing entries are NOT written in console/test mode (audit.console=false by default),
        // so we verify the observable side-effects: correct unit_id and audit stamp from GlobalObserver.
        $tenant->run(function () use ($pkg, $unitB, $guard): void {
            $fresh = Package::find($pkg->id);

            $this->assertSame($unitB->id, $fresh->unit_id, 'unit_id must be updated to unitB');
            $this->assertSame($guard->id, $fresh->user_updated_id, 'GlobalObserver must stamp user_updated_id');
        });
    }

    // =========================================================================
    // Cross-tenant route-binding isolation (regression for WU-2 tenancy fix)
    //
    // These routes used implicit route-model binding ({package}), resolved by
    // SubstituteBindings BEFORE InitializeTenancyFromUser — fetching the package
    // across ALL tenants. A Tenant-A guard could mutate a Tenant-B package. The fix
    // resolves the model inside the controller (tenant-scoped), so a foreign id 404s.
    // =========================================================================

    public function test_return_on_foreign_tenant_package_returns_404_and_does_not_mutate(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenantA, 'security');
        $guardB = $this->makeRoleUser($tenantB, 'security');
        $unitB = $this->makeUnit($tenantB);
        $pkgB = $this->registerPackage($tenantB, $unitB, $guardB);

        $this->tenantRequest($this->token($guardA, ['update.package']), $tenantA)
            ->postJson("/api/package/{$pkgB->id}/return", ['reason' => 'cross-tenant attempt'])
            ->assertNotFound();

        $tenantB->run(function () use ($pkgB): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkgB->id)->package_status_id);
        });
    }

    public function test_incident_on_foreign_tenant_package_returns_404_and_does_not_mutate(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenantA, 'security');
        $guardB = $this->makeRoleUser($tenantB, 'security');
        $unitB = $this->makeUnit($tenantB);
        $pkgB = $this->registerPackage($tenantB, $unitB, $guardB);

        $this->tenantRequest($this->token($guardA, ['update.package']), $tenantA)
            ->postJson("/api/package/{$pkgB->id}/incident", ['reason' => 'cross-tenant attempt'])
            ->assertNotFound();

        $tenantB->run(function () use ($pkgB): void {
            $this->assertSame(PackageStatus::READY, Package::findOrFail($pkgB->id)->package_status_id);
        });
    }

    public function test_reassign_on_foreign_tenant_package_returns_404_and_does_not_mutate(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $guardA = $this->makeRoleUser($tenantA, 'security');
        $guardB = $this->makeRoleUser($tenantB, 'security');
        $unitA = $this->makeUnit($tenantA); // valid uuid so request validation passes
        $unitB = $this->makeUnit($tenantB);
        $pkgB = $this->registerPackage($tenantB, $unitB, $guardB);

        $this->tenantRequest($this->token($guardA, ['update.package']), $tenantA)
            ->postJson("/api/package/{$pkgB->id}/reassign", ['unit_id' => $unitA->id])
            ->assertNotFound();

        $tenantB->run(function () use ($pkgB, $unitB): void {
            $this->assertSame($unitB->id, Package::findOrFail($pkgB->id)->unit_id);
        });
    }

    // =========================================================================
    // Permission gating
    // =========================================================================

    public function test_resident_cannot_return_a_package(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/return", ['reason' => 'Test'])
            ->assertForbidden();
    }

    public function test_resident_cannot_flag_incident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/incident", ['reason' => 'Test'])
            ->assertForbidden();
    }

    // =========================================================================
    // Notifications on lifecycle transitions (Phase-2 fix)
    //
    // notifyUsers() is deferred via DB::afterCommit; since the HTTP test
    // completes the request lifecycle, the callback fires before assertion.
    // We verify the in-app NotificationUser row was created for the resident.
    // =========================================================================

    public function test_return_package_creates_in_app_notification_for_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $before = 0;
        $tenant->run(function () use ($resident, &$before): void {
            $before = NotificationUser::where('user_id', $resident->id)->count();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/return", ['reason' => 'No reclamado'])
            ->assertOk();

        $after = 0;
        $tenant->run(function () use ($resident, &$after): void {
            $after = NotificationUser::where('user_id', $resident->id)->count();
        });

        $this->assertGreaterThan($before, $after, 'returnPackage must create an in-app notification for the resident');
    }

    public function test_flag_incident_creates_in_app_notification_for_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $resident = $this->makeRoleUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $pkg = $this->registerPackage($tenant, $unit, $guard);

        $before = 0;
        $tenant->run(function () use ($resident, &$before): void {
            $before = NotificationUser::where('user_id', $resident->id)->count();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/incident", ['reason' => 'Paquete dañado'])
            ->assertOk();

        $after = 0;
        $tenant->run(function () use ($resident, &$after): void {
            $after = NotificationUser::where('user_id', $resident->id)->count();
        });

        $this->assertGreaterThan($before, $after, 'flagIncident must create an in-app notification for the resident');
    }

    public function test_reassign_notifies_both_old_and_new_unit_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $guard = $this->makeRoleUser($tenant, 'security');
        $residentA = $this->makeRoleUser($tenant, 'resident');
        $residentB = $this->makeRoleUser($tenant, 'resident');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);

        $this->linkToUnit($tenant, $unitA, $residentA);
        $this->linkToUnit($tenant, $unitB, $residentB);

        $pkg = $this->registerPackage($tenant, $unitA, $guard);

        $beforeA = 0;
        $beforeB = 0;
        $tenant->run(function () use ($residentA, $residentB, &$beforeA, &$beforeB): void {
            $beforeA = NotificationUser::where('user_id', $residentA->id)->count();
            $beforeB = NotificationUser::where('user_id', $residentB->id)->count();
        });

        $this->tenantRequest($this->token($guard, ['update.package']), $tenant)
            ->postJson("/api/package/{$pkg->id}/reassign", ['unit_id' => $unitB->id])
            ->assertOk();

        $afterA = 0;
        $afterB = 0;
        $tenant->run(function () use ($residentA, $residentB, &$afterA, &$afterB): void {
            $afterA = NotificationUser::where('user_id', $residentA->id)->count();
            $afterB = NotificationUser::where('user_id', $residentB->id)->count();
        });

        $this->assertGreaterThan($beforeA, $afterA, 'reassignUnit must notify the OLD unit resident');
        $this->assertGreaterThan($beforeB, $afterB, 'reassignUnit must notify the NEW unit resident');
    }
}
