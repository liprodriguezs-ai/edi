<?php

declare(strict_types=1);

namespace Modules\Clinic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Municipality\Models\Municipality;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CL-001 / REQ-CL-002 / REQ-XC-001 / REQ-XC-002 coverage for health_center.
 */
final class HealthCenterApiTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeMunicipality(): Municipality
    {
        return HealthCenterFactory::defaultMunicipality();
    }

    // ── REQ-CL-001 ──────────────────────────────────────────────────────────

    public function test_create_health_center_happy_path(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $municipality = $this->makeMunicipality();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/health_center', [
                'name' => 'Centro de Salud Norte',
                'municipality_id' => $municipality->id,
                'address' => 'Av. Siempre Viva 123',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.is_active', true);

        $tenant->run(function () use ($tenant): void {
            $center = HealthCenter::first();
            $this->assertSame($tenant->id, $center->tenant_id);
        });
    }

    public function test_create_health_center_with_unknown_municipality_fails_with_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/health_center', [
                'name' => 'Centro Falso',
                'municipality_id' => (string) Str::uuid7(),
                'address' => 'Calle Falsa 123',
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, HealthCenter::count());
        });
    }

    // ── REQ-CL-002 ──────────────────────────────────────────────────────────

    public function test_duplicate_clues_within_tenant_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $municipality = $this->makeMunicipality();

        $tenant->run(function () use ($municipality): void {
            HealthCenter::factory()->create(['clues_code' => 'DFSSA003962', 'municipality_id' => $municipality->id]);
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/health_center', [
                'name' => 'Otro centro',
                'clues_code' => 'dfssa003962',
                'municipality_id' => $municipality->id,
                'address' => 'Calle 2',
            ])
            ->assertStatus(422);
    }

    public function test_same_clues_in_another_tenant_is_allowed(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $municipalityA = $this->makeMunicipality();
        $municipalityB = $this->makeMunicipality();

        $tenantA->run(function () use ($municipalityA): void {
            HealthCenter::factory()->create(['clues_code' => 'DFSSA003962', 'municipality_id' => $municipalityA->id]);
        });

        $userB = $this->makeUser($tenantB);
        $tokenB = $userB->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/health_center', [
                'name' => 'Centro B',
                'clues_code' => 'DFSSA003962',
                'municipality_id' => $municipalityB->id,
                'address' => 'Calle B',
            ])
            ->assertCreated();
    }

    public function test_soft_deleted_clues_is_reusable(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $municipality = $this->makeMunicipality();

        $tenant->run(function () use ($municipality): void {
            $center = HealthCenter::factory()->create(['clues_code' => 'DFSSA003962', 'municipality_id' => $municipality->id]);
            $center->delete();
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/health_center', [
                'name' => 'Centro nuevo',
                'clues_code' => 'DFSSA003962',
                'municipality_id' => $municipality->id,
                'address' => 'Calle nueva',
            ])
            ->assertCreated();
    }

    // ── REQ-XC-001 ──────────────────────────────────────────────────────────

    public function test_cross_tenant_show_is_404(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $municipality = $this->makeMunicipality();

        $centerB = $this->inTenant($tenantB, function () use ($municipality): HealthCenter {
            return HealthCenter::factory()->create(['municipality_id' => $municipality->id]);
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson('/api/health_center/'.$centerB->id)
            ->assertStatus(404);
    }

    // ── REQ-XC-002 ──────────────────────────────────────────────────────────

    public function test_role_without_permission_is_denied(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['read.health_center'])->plainTextToken;
        $municipality = $this->makeMunicipality();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/health_center', [
                'name' => 'Centro sin permiso',
                'municipality_id' => $municipality->id,
                'address' => 'Calle X',
            ])
            ->assertStatus(403);
    }
}
