<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\Vehicle;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

/**
 * housing-04 / global-04 — §6.2 «Ver lista de unidades: Resi ❌», §6.4.1
 * «Muestra solo SU unidad»: a resident/renter-only actor sees ONLY the units,
 * associations and vehicles of their own unit(s); staff roles keep the full
 * cluster view; GET /api/unit/my serves the "Mi unidad" view.
 */
final class ResidentScopingTest extends TestCase
{
    use HousingTestHelpers;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, token: string, ownUnit: Unit, otherUnit: Unit, ownVehicle: Vehicle, otherVehicle: Vehicle}
     */
    private function buildResidentWorld(string $roleSlug = 'resident'): array
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant('Scoping');
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant, $roleSlug);
        $neighbor = $this->makeUser($tenant);

        $ownUnit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);

        $this->attachUnitUser($tenant, $ownUnit, $neighbor);
        $this->attachUnitUser(
            $tenant,
            $ownUnit,
            $resident,
            $roleSlug === 'renter' ? UnitRelationship::RENTER : UnitRelationship::FAMILY_RESIDENT,
            ['is_billing_owner' => false, 'is_legal_representative' => false],
        );
        $this->attachUnitUser($tenant, $otherUnit, $neighbor, UnitRelationship::OWNER, ['is_billing_owner' => false, 'is_legal_representative' => false]);

        $ownVehicle = $tenant->run(static fn (): Vehicle => Vehicle::create([
            'unit_id' => $ownUnit->id,
            'plate' => 'OWN-'.Str::upper(Str::random(5)),
        ]));
        $otherVehicle = $tenant->run(static fn (): Vehicle => Vehicle::create([
            'unit_id' => $otherUnit->id,
            'plate' => 'NBR-'.Str::upper(Str::random(5)),
        ]));

        return [
            'tenant' => $tenant,
            'token' => $this->tokenFor($resident),
            'ownUnit' => $ownUnit,
            'otherUnit' => $otherUnit,
            'ownVehicle' => $ownVehicle,
            'otherVehicle' => $otherVehicle,
        ];
    }

    public function test_resident_unit_index_returns_only_their_units(): void
    {
        $world = $this->buildResidentWorld();

        $response = $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/unit')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($world['ownUnit']->id));
        $this->assertFalse($ids->contains($world['otherUnit']->id));
    }

    public function test_resident_unit_search_is_scoped_too(): void
    {
        $world = $this->buildResidentWorld();

        $response = $this->tenantRequest($world['token'], $world['tenant'])
            ->postJson('/api/unit/search', [])
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($world['ownUnit']->id));
        $this->assertFalse($ids->contains($world['otherUnit']->id));
    }

    public function test_resident_cannot_show_a_neighbor_unit(): void
    {
        $world = $this->buildResidentWorld();

        $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/unit/'.$world['otherUnit']->id)
            ->assertStatus(404);

        $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/unit/'.$world['ownUnit']->id)
            ->assertStatus(200);
    }

    public function test_resident_unit_user_index_is_limited_to_their_units(): void
    {
        $world = $this->buildResidentWorld();

        $response = $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/unit_user')
            ->assertStatus(200);

        $unitIds = collect($response->json('data'))->pluck('unit_id')->unique();
        $this->assertSame([$world['ownUnit']->id], $unitIds->values()->all());
    }

    public function test_resident_vehicle_index_is_limited_to_their_units(): void
    {
        $world = $this->buildResidentWorld();

        $response = $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/vehicle')
            ->assertStatus(200);

        $plates = collect($response->json('data'))->pluck('plate');
        $this->assertTrue($plates->contains($world['ownVehicle']->plate));
        $this->assertFalse($plates->contains($world['otherVehicle']->plate));
    }

    public function test_renter_is_scoped_like_resident(): void
    {
        $world = $this->buildResidentWorld('renter');

        $response = $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/unit')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($world['ownUnit']->id));
        $this->assertFalse($ids->contains($world['otherUnit']->id));
    }

    public function test_administrator_keeps_full_cluster_view(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant('Scoping Admin');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'administrator');
        $owner = $this->makeUser($tenant);
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $this->attachUnitUser($tenant, $unitA, $owner);

        $response = $this->tenantRequest($this->tokenFor($admin), $tenant)
            ->getJson('/api/unit')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($unitA->id));
        $this->assertTrue($ids->contains($unitB->id));
    }

    public function test_my_units_returns_own_units_with_active_associations(): void
    {
        $world = $this->buildResidentWorld();

        $response = $this->tenantRequest($world['token'], $world['tenant'])
            ->getJson('/api/unit/my')
            ->assertStatus(200);

        $data = collect($response->json('data'));
        $this->assertCount(1, $data);
        $this->assertSame($world['ownUnit']->id, $data->first()['id']);
        $this->assertNotEmpty($data->first()['unit_users']);
        $this->assertArrayHasKey('full_identifier', $data->first());
    }

    public function test_my_units_requires_read_unit_ability(): void
    {
        $tenant = $this->makeTenant('Scoping NoAbility');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);

        $this->tenantRequest($this->tokenFor($user, ['read.dashboard']), $tenant)
            ->getJson('/api/unit/my')
            ->assertStatus(403);
    }
}
