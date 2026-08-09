<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\Vehicle;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Proves the Orion ↔ module FormRequest wiring (DelegatingModuleRequest).
 *
 * Orion resolves ONE request class per controller and previously fell back to
 * its rule-less base Request because no App\Http\Requests\{Model}Request exists,
 * so the per-module {Model}CreateRequest/{Model}UpdateRequest never ran
 * (findings sec-tenant-isolation-01/02, housing-02/03). The QCH core now falls
 * back to DelegatingModuleRequest, which delegates store/update rules to the
 * sibling module requests by convention.
 */
final class OrionValidationWiringTest extends TestCase
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

    private function makeUserToken(Tenant $tenant): string
    {
        $user = User::create([
            'name' => 'Core Wiring Admin '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);

        return $user->createToken('admin', ['*'])->plainTextToken;
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

    private function makeUnit(Tenant $tenant): Unit
    {
        return $tenant->run(static fn (): Unit => Unit::create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => Str::random(6),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ]));
    }

    public function test_store_with_invalid_payload_returns_422_with_error_structure(): void
    {
        $tenant = $this->makeTenant('Wiring A');
        $this->seedTenant($tenant);
        $token = $this->makeUserToken($tenant);
        $unit = $this->makeUnit($tenant);

        $this->tenantRequest($token, $tenant)->postJson('/api/vehicle', [
            'unit_id' => $unit->id,
            // plate missing → VehicleCreateRequest must reject, not insert raw
        ])->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors(['plate']);
    }

    public function test_store_with_cross_tenant_unit_id_returns_422(): void
    {
        $tenantA = $this->makeTenant('Wiring Cross A');
        $tenantB = $this->makeTenant('Wiring Cross B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);
        $token = $this->makeUserToken($tenantA);
        $foreignUnit = $this->makeUnit($tenantB);

        $this->tenantRequest($token, $tenantA)->postJson('/api/vehicle', [
            'unit_id' => $foreignUnit->id,
            'plate' => 'XTE-123',
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);

        $tenantA->run(function (): void {
            $this->assertSame(0, Vehicle::count());
        });
    }

    public function test_update_with_invalid_payload_returns_422(): void
    {
        $tenant = $this->makeTenant('Wiring Update');
        $this->seedTenant($tenant);
        $token = $this->makeUserToken($tenant);
        $unit = $this->makeUnit($tenant);

        $vehicle = $tenant->run(static fn (): Vehicle => Vehicle::create([
            'unit_id' => $unit->id,
            'plate' => 'AAA-001',
        ]));

        $this->tenantRequest($token, $tenant)->patchJson('/api/vehicle/'.$vehicle->id, [
            'plate' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['plate']);

        $tenant->run(function () use ($vehicle): void {
            $this->assertSame('AAA-001', $vehicle->fresh()->plate);
        });
    }

    public function test_store_and_update_with_valid_payload_keep_working(): void
    {
        $tenant = $this->makeTenant('Wiring Happy');
        $this->seedTenant($tenant);
        $token = $this->makeUserToken($tenant);
        $unit = $this->makeUnit($tenant);

        $created = $this->tenantRequest($token, $tenant)->postJson('/api/vehicle', [
            'unit_id' => $unit->id,
            'plate' => 'HPY-777',
            'make' => 'Nissan',
        ])->assertStatus(201)->json('data');

        $this->tenantRequest($token, $tenant)->patchJson('/api/vehicle/'.$created['id'], [
            'color' => 'Rojo',
        ])->assertStatus(200);

        $tenant->run(function () use ($created): void {
            $vehicle = Vehicle::findOrFail($created['id']);
            $this->assertSame('HPY-777', $vehicle->plate);
            $this->assertSame('Rojo', $vehicle->color);
        });
    }

    /**
     * Regression for sec-tenant-isolation-02: exists() rules against CENTRAL
     * catalogs (no tenant_id column) must run without the tenant where —
     * a valid central FK passes (201) and an unknown one is a clean 422, not
     * an SQLSTATE 42703 "column tenant_id does not exist" 500.
     */
    public function test_central_catalog_exists_rule_runs_without_tenant_scope(): void
    {
        $tenant = $this->makeTenant('Wiring Central');
        $this->seedTenant($tenant);
        $token = $this->makeUserToken($tenant);

        $this->tenantRequest($token, $tenant)->postJson('/api/bank_account', [
            'name' => 'Caja general',
            'bank_account_type_id' => BankAccountType::CASH,
            'initial_balance' => 0,
        ])->assertStatus(201);

        $this->tenantRequest($token, $tenant)->postJson('/api/bank_account', [
            'name' => 'Cuenta fantasma',
            'bank_account_type_id' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors(['bank_account_type_id']);
    }

    public function test_duplicate_plate_in_same_tenant_returns_422_instead_of_500(): void
    {
        $tenant = $this->makeTenant('Wiring Dup');
        $this->seedTenant($tenant);
        $token = $this->makeUserToken($tenant);
        $unit = $this->makeUnit($tenant);

        $payload = ['unit_id' => $unit->id, 'plate' => 'DUP-001'];

        $this->tenantRequest($token, $tenant)->postJson('/api/vehicle', $payload)->assertStatus(201);
        $this->tenantRequest($token, $tenant)->postJson('/api/vehicle', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate']);
    }
}
