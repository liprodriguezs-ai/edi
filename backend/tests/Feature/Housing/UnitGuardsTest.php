<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\TestCase;

/**
 * housing-09 — §6.5 r2: a unit with charge/payment history cannot be deleted
 * (422, mark inactive instead). housing-16 — §6.3.3: duplicate unit identity
 * answers 422 with the spec message, not a 500 from the unique index.
 * sec-tenant-isolation-03 — the unit_unit_feature pivot accepts attach()/sync().
 */
final class UnitGuardsTest extends TestCase
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

    private function makeChargeFor(Tenant $tenant, Unit $unit): Charge
    {
        return $tenant->run(static function () use ($unit): Charge {
            $concept = ChargeConcept::create([
                'code' => 'MANT_'.Str::upper(Str::random(6)),
                'name' => 'Mantenimiento',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'is_active' => true,
            ]);

            return Charge::create([
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 150000,
                'issued_at' => '2026-06-01',
                'due_date' => '2026-06-10',
                'charge_status_id' => ChargeStatus::PENDING,
            ]);
        });
    }

    // =========================================================================
    // housing-09 — delete guard
    // =========================================================================

    public function test_unit_with_charge_history_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant('Guard Delete');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeChargeFor($tenant, $unit);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/unit/'.$unit->id)
            ->assertStatus(422);

        // Force delete is blocked by the same guard.
        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/unit/'.$unit->id.'?force=true')
            ->assertStatus(422);

        $tenant->run(function () use ($unit): void {
            $this->assertNotNull(Unit::find($unit->id));
        });
    }

    public function test_unit_without_history_can_be_deleted(): void
    {
        $tenant = $this->makeTenant('Guard Free');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/unit/'.$unit->id)
            ->assertStatus(200);

        $tenant->run(function () use ($unit): void {
            $this->assertNull(Unit::find($unit->id));
            $this->assertNotNull(Unit::withTrashed()->find($unit->id));
        });
    }

    // =========================================================================
    // housing-16 — duplicate identity → 422 with spec message
    // =========================================================================

    public function test_duplicate_house_number_returns_422_with_spec_message(): void
    {
        $tenant = $this->makeTenant('Guard Dup House');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $payload = [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => '23',
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ];

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', $payload)->assertStatus(201);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number'])
            ->assertJsonFragment(['number' => ['Ya existe una Casa 23 en este condominio.']]);
    }

    public function test_duplicate_apartment_identity_returns_422(): void
    {
        $tenant = $this->makeTenant('Guard Dup Apt');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $towerId = $this->tenantRequest($token, $tenant)->postJson('/api/tower', [
            'name' => 'Torre Norte',
        ])->assertStatus(201)->json('data.id');

        $payload = [
            'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
            'number' => '502',
            'tower_id' => $towerId,
            'floor' => '5',
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ];

        $this->tenantRequest($token, $tenant)->postJson('/api/unit', $payload)->assertStatus(201);
        $this->tenantRequest($token, $tenant)->postJson('/api/unit', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number']);

        // Same number on another floor is a DIFFERENT identity → accepted.
        $this->tenantRequest($token, $tenant)->postJson('/api/unit', array_merge($payload, ['floor' => '6']))
            ->assertStatus(201);
    }

    public function test_update_into_colliding_identity_returns_422(): void
    {
        $tenant = $this->makeTenant('Guard Dup Update');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $unitA = $this->makeUnit($tenant, ['number' => 'A-1']);
        $unitB = $this->makeUnit($tenant, ['number' => 'B-1']);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)->patchJson('/api/unit/'.$unitB->id, [
            'number' => 'A-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['number']);

        $tenant->run(function () use ($unitB): void {
            $this->assertSame('B-1', $unitB->fresh()->number);
        });

        // Updating the unit onto its OWN identity stays valid.
        $this->tenantRequest($token, $tenant)->patchJson('/api/unit/'.$unitB->id, [
            'number' => 'B-1',
            'notes' => 'sin cambios de identidad',
        ])->assertStatus(200);
    }

    // =========================================================================
    // sec-tenant-isolation-03 — feature pivot works with attach()/sync()
    // =========================================================================

    public function test_unit_features_can_be_attached_and_synced(): void
    {
        $tenant = $this->makeTenant('Guard Pivot');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $patio = UnitFeature::create(['name' => 'Patio '.Str::random(4)]);
            $roof = UnitFeature::create(['name' => 'Roof '.Str::random(4)]);

            $unit->features()->attach($patio->id);
            $this->assertSame(1, $unit->features()->count());

            $unit->features()->sync([$patio->id, $roof->id]);
            $this->assertSame(2, $unit->fresh()->features()->count());
        });
    }
}
