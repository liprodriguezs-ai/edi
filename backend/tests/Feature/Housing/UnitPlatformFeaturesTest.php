<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Platform/cross-cutting Housing features (§6.3.3, §6.5):
 *  - characteristics JSONB custom attributes (r5)
 *  - full_identifier accessor (r6)
 *  - indiviso sum ≤ 100% validation (§6.3.3)
 *  - apartment requires tower/floor/number (§6.3.3)
 *  - field maxes (notes, built_m2/land_m2)
 */
final class UnitPlatformFeaturesTest extends TestCase
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

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Housing Admin '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function tenantRequest(string $token, Tenant $tenant): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id);

        return $this;
    }

    // =========================================================================
    // §6.5 r5 — characteristics JSONB
    // =========================================================================

    public function test_unit_characteristics_json_round_trips_as_array(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => 'C1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'characteristics' => ['cisterna' => true, 'm2_jardin' => 30],
            ]);

            $fresh = $unit->fresh();
            $this->assertIsArray($fresh->characteristics);
            $this->assertTrue($fresh->characteristics['cisterna']);
            $this->assertSame(30, $fresh->characteristics['m2_jardin']);
        });
    }

    // =========================================================================
    // §6.5 r6 — full_identifier accessor
    // =========================================================================

    public function test_full_identifier_for_house(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => '23',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            $label = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'))->name;
            $this->assertSame($label.' 23', $unit->fresh()->full_identifier);
        });
    }

    public function test_full_identifier_for_apartment_composes_tower_floor_number(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $tower = Tower::create(['name' => 'Norte', 'order' => 0]);
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'tower_id' => $tower->id,
                'floor' => '5',
                'number' => '502',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            $this->assertSame('Torre Norte · Piso 5 · Depto 502', $unit->fresh()->full_identifier);
        });
    }

    // =========================================================================
    // §6.3.3 — indiviso sum ≤ 100%
    // =========================================================================

    public function test_units_summing_to_exactly_100_are_accepted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('admin', ['*'])->plainTextToken;

        // Two units of 50% each = 100% → ok.
        foreach (['A', 'B'] as $n) {
            $this->tenantRequest($token, $tenant)->postJson('/api/unit', [
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => $n,
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'ownership_share' => 50,
            ])->assertStatus(201);
        }
    }

    public function test_unit_pushing_share_over_100_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => 'A',
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'ownership_share' => 80,
        ])->assertStatus(201);

        // 80 + 30 = 110 > 100 → must fail.
        $this->tenantRequest($token, $tenant)->postJson('/api/unit', [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => 'B',
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'ownership_share' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors(['ownership_share']);
    }

    // =========================================================================
    // §6.3.3 — apartment requires tower/floor/number
    // =========================================================================

    public function test_apartment_without_tower_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', [
            'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
            'number' => '101',
            'floor' => '1',
            // tower_id missing
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ])->assertStatus(422)->assertJsonValidationErrors(['tower_id']);
    }

    public function test_house_without_tower_is_accepted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => '7',
            // no tower/floor
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ])->assertStatus(201);
    }

    // =========================================================================
    // §6.3.3 — field maxes
    // =========================================================================

    public function test_built_m2_over_max_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => 'big',
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'built_m2' => 100000, // > 9999.99
        ])->assertStatus(422)->assertJsonValidationErrors(['built_m2']);
    }
}
