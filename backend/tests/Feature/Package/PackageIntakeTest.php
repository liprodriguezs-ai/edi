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
use Modules\Package\Models\PackageCarrier;
use Modules\Package\Models\PackageStatus;
use Modules\Package\Models\PackageType;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for Package intake endpoints (WU-1 / package-intake spec):
 *
 *  - Guard registers a package via POST /api/package
 *  - Batch registration via POST /api/package/batch
 *  - Resident sees own unit's packages; pickup_code visible only to unit members
 *  - Non-member cannot see pickup_code
 *  - Resident cannot see packages from another unit (query isolation)
 */
final class PackageIntakeTest extends TestCase
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

    /** @return array<string, string> */
    private function catalogIds(Tenant $tenant): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids): void {
            $ids['carrier_id'] = PackageCarrier::first()->id;
            $ids['type_id'] = PackageType::first()->id;
        });

        return $ids;
    }

    // =========================================================================
    // Single package intake
    // =========================================================================

    public function test_guard_can_register_package_for_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $payload = [
            'unit_id' => $unit->id,
            'package_carrier_id' => $catalog['carrier_id'],
            'package_type_id' => $catalog['type_id'],
        ];

        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', $payload)
            ->assertCreated()
            ->assertJsonPath('data.package_status_id', PackageStatus::READY);
    }

    public function test_registered_package_does_not_expose_pickup_code_to_non_member(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // Create package
        $response = $this->tenantRequest($this->token($security, ['create.package', 'read.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        $packageId = $response->json('data.id');

        // Admin with create.package can see ALL packages (not scoped to their units),
        // but pickup_code is still hidden because admin is NOT a unit member.
        $admin = $this->makeUser($tenant, 'administrator');
        $data = $this->tenantRequest($this->token($admin, ['read.package', 'create.package']), $tenant)
            ->getJson("/api/package/{$packageId}")
            ->assertOk()
            ->json('data');

        // pickup_code is in $hidden; non-unit-member should not see it regardless of role
        $this->assertArrayNotHasKey('pickup_code', $data);
    }

    public function test_unit_member_can_see_pickup_code(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $resident = $this->makeUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $this->linkToUnit($tenant, $unit, $resident);

        // Guard creates the package
        $response = $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        $packageId = $response->json('data.id');

        // Resident reads the package — they ARE a member so pickup_code is visible
        $data = $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson("/api/package/{$packageId}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('pickup_code', $data);
    }

    public function test_resident_cannot_see_packages_from_other_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $resident = $this->makeUser($tenant, 'resident');
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // Resident belongs to unitA only
        $this->linkToUnit($tenant, $unitA, $resident);

        // Guard creates a package for unitB
        $tenantToken = $this->token($security, ['create.package', 'read.package']);
        $response = $this->tenantRequest($tenantToken, $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitB->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        $packageId = $response->json('data.id');

        // Resident reads by ID — should 404 since their query is scoped to unitA
        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson("/api/package/{$packageId}")
            ->assertNotFound();
    }

    /**
     * Board read-only roles (Treasurer, Oversight Committee) carry read.package +
     * read.report_package but NOT create.package. They must see ALL tenant packages
     * in the list, not just their own unit's — so the list matches the aging report.
     */
    public function test_board_readonly_role_sees_all_tenant_packages_in_index(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $treasurer = $this->makeUser($tenant, 'treasurer');

        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // Treasurer belongs to unitA only — if scoped, they would only see unitA packages.
        $this->linkToUnit($tenant, $unitA, $treasurer);

        $guardToken = $this->token($security, ['create.package', 'read.package']);

        // Package in unitA
        $this->tenantRequest($guardToken, $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitA->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        // Package in unitB — the treasurer would miss this if scoped to their own unit
        $this->tenantRequest($guardToken, $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitB->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        // Treasurer token: read.package + read.report_package, NO create.package.
        $treasurerToken = $this->token($treasurer, ['read.package', 'read.report_package']);

        $response = $this->tenantRequest($treasurerToken, $tenant)
            ->getJson('/api/package')
            ->assertOk();

        // Both packages (from both units) must be visible to the treasurer.
        $this->assertCount(2, $response->json('data'));
    }

    /**
     * A resident (read.package only, no read.report_package) must still be scoped
     * to their own active units — confirmed complement of the board-readonly case above.
     */
    public function test_resident_without_report_ability_stays_scoped_to_own_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $resident = $this->makeUser($tenant, 'resident');

        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // Resident belongs to unitA only.
        $this->linkToUnit($tenant, $unitA, $resident);

        $guardToken = $this->token($security, ['create.package', 'read.package']);

        // Package in unitA (resident's unit)
        $this->tenantRequest($guardToken, $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitA->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        // Package in unitB (NOT the resident's unit)
        $this->tenantRequest($guardToken, $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitB->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        // Resident token: read.package only — no report ability.
        $residentToken = $this->token($resident, ['read.package']);

        $response = $this->tenantRequest($residentToken, $tenant)
            ->getJson('/api/package')
            ->assertOk();

        // Only the unitA package must be visible to the resident.
        $this->assertCount(1, $response->json('data'));
    }

    public function test_package_intake_requires_unit_id(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $catalog = $this->catalogIds($tenant);

        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_id']);
    }

    // =========================================================================
    // Batch intake
    // =========================================================================

    public function test_guard_can_batch_register_multiple_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $packages = [];
        for ($i = 0; $i < 3; $i++) {
            $packages[] = [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
                'description' => "Paquete de prueba {$i}",
            ];
        }

        $response = $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package/intake-batch', ['packages' => $packages])
            ->assertCreated();

        $this->assertCount(3, $response->json('data'));

        // All must be in READY status
        foreach ($response->json('data') as $pkg) {
            $this->assertSame(PackageStatus::READY, $pkg['package_status_id']);
        }
    }

    public function test_batch_intake_rejects_empty_array(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');

        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package/intake-batch', ['packages' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packages']);
    }

    public function test_batch_intake_rejects_over_fifty_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $packages = array_fill(0, 51, [
            'unit_id' => $unit->id,
            'package_carrier_id' => $catalog['carrier_id'],
        ]);

        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package/intake-batch', ['packages' => $packages])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packages']);
    }

    public function test_resident_cannot_batch_register_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant, 'resident');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // Payload is fully valid, so the only thing that can fail is the controller's
        // create.package authorization — the resident token lacks that ability → 403.
        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->postJson('/api/package/intake-batch', [
                'packages' => [
                    [
                        'unit_id' => $unit->id,
                        'package_carrier_id' => $catalog['carrier_id'],
                        'package_type_id' => $catalog['type_id'],
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    // =========================================================================
    // Search by recipient_name (FIX 3 — searchableBy drift)
    // =========================================================================

    /**
     * Packages must be findable by recipient_name via POST /api/package/search.
     * Before this fix, recipient_name was absent from searchableBy() so searching
     * a resident's name returned an empty result set.
     */
    public function test_package_is_searchable_by_recipient_name(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // Register a package with a distinctive recipient name.
        $this->tenantRequest($this->token($security, ['create.package', 'read.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
                'recipient_name' => 'Valentina Morales',
            ])
            ->assertCreated();

        // Register a second package with a different recipient name — must NOT appear.
        $this->tenantRequest($this->token($security, ['create.package', 'read.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
                'recipient_name' => 'Carlos Ruiz',
            ])
            ->assertCreated();

        // Guard searches by the first recipient's name via the Orion search endpoint.
        $response = $this->tenantRequest($this->token($security, ['create.package', 'read.package']), $tenant)
            ->postJson('/api/package/search', [
                'search' => ['value' => 'Valentina'],
            ])
            ->assertOk();

        $data = $response->json('data');

        // The search must return exactly the package whose recipient_name contains "Valentina".
        $this->assertCount(1, $data, 'Exactly one package must match the recipient_name search');
        $this->assertSame('Valentina Morales', $data[0]['recipient_name']);
    }

    // =========================================================================
    // Received_at + received_by stamping
    // =========================================================================

    public function test_package_stamps_received_at_and_received_by_on_intake(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $response = $this->tenantRequest($this->token($security, ['create.package', 'read.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertCreated();

        $packageId = $response->json('data.id');

        $tenant->run(function () use ($packageId, $security): void {
            $pkg = Package::findOrFail($packageId);
            $this->assertNotNull($pkg->received_at);
            $this->assertSame($security->id, $pkg->received_by_user_id);
        });
    }
}
