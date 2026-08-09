<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;
use Tests\TestCase;

/**
 * housing-01 / housing-13 — the unit_user resource works END TO END via HTTP:
 * the module FormRequests run (DelegatingModuleRequest wiring), validated()
 * carries the real payload into UnitUserService, and the §6.3.4 invariants are
 * enforced at the API boundary (no more 500-on-store / silent no-op-on-update).
 */
final class UnitUserHttpTest extends TestCase
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

    public function test_store_creates_association_via_http(): void
    {
        $tenant = $this->makeTenant('UU Store');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_user', [
            'unit_id' => $unit->id,
            'user_id' => $resident->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
            'is_billing_owner' => true,
            'start_date' => '2026-06-01',
        ])->assertStatus(201);

        $tenant->run(function () use ($unit, $resident): void {
            $association = UnitUser::where('unit_id', $unit->id)->where('user_id', $resident->id)->first();
            $this->assertNotNull($association);
            $this->assertTrue($association->is_billing_owner);
            $this->assertNull($association->end_date);
        });
    }

    public function test_store_without_unit_id_returns_422(): void
    {
        $tenant = $this->makeTenant('UU Invalid');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $resident = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_user', [
            'user_id' => $resident->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);

        $tenant->run(function (): void {
            $this->assertSame(0, UnitUser::count());
        });
    }

    public function test_store_renter_without_active_owner_returns_422(): void
    {
        $tenant = $this->makeTenant('UU Renter');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $renter = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_user', [
            'unit_id' => $unit->id,
            'user_id' => $renter->id,
            'unit_relationship_id' => UnitRelationship::RENTER,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_relationship_id']);
    }

    public function test_update_closes_association_with_end_date(): void
    {
        $tenant = $this->makeTenant('UU Close');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $owner = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $association = $this->attachUnitUser($tenant, $unit, $owner);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->patchJson('/api/unit_user/'.$association->id, [
            'end_date' => '2026-06-30',
            'notes' => 'Mudanza por trabajo',
        ])->assertStatus(200);

        $tenant->run(function () use ($association): void {
            $fresh = $association->fresh();
            $this->assertSame('2026-06-30', $fresh->end_date?->toDateString());
            $this->assertSame('Mudanza por trabajo', $fresh->notes);
        });
    }

    public function test_store_new_billing_owner_unsets_previous_one(): void
    {
        $tenant = $this->makeTenant('UU Billing');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $previousOwner = $this->makeUser($tenant);
        $billingContact = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $previous = $this->attachUnitUser($tenant, $unit, $previousOwner);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_user', [
            'unit_id' => $unit->id,
            'user_id' => $billingContact->id,
            'unit_relationship_id' => UnitRelationship::BILLING_CONTACT,
            'is_billing_owner' => true,
        ])->assertStatus(201);

        $tenant->run(function () use ($previous): void {
            $this->assertFalse($previous->fresh()->is_billing_owner);
        });
    }

    public function test_resident_cannot_create_associations(): void
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant('UU Authz');
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant, 'resident');
        $other = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $token = $this->tokenFor($resident);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_user', [
            'unit_id' => $unit->id,
            'user_id' => $other->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
        ])->assertStatus(403);
    }

    /**
     * housing-11 regression — the catalogs no longer whitelist the nonexistent
     * 'key' column: Orion answers a clean 422, not an SQL undefined-column 500.
     */
    public function test_catalog_sort_by_key_is_rejected_as_unknown_field(): void
    {
        $tenant = $this->makeTenant('UU Catalog');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_status/search', [
            'sort' => [['field' => 'key', 'direction' => 'asc']],
        ])->assertStatus(422);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit_status/search', [
            'sort' => [['field' => 'name', 'direction' => 'asc']],
        ])->assertStatus(200);
    }
}
