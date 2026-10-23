<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-013 — a user of tenant B can neither read nor mutate tenant A
 * screenings; screenings cannot reference another tenant's visit.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_cross_tenant_screening_is_invisible(): void
    {
        $this->seedPlatformRegistry();
        $tenantA = $this->makeTenant('Organization A');
        $tenantB = $this->makeTenant('Organization B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);
        $tokenA = $this->administratorToken($tenantA);
        $tokenB = $this->administratorToken($tenantB);

        $fxA = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $screeningA = $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/screening', ['visit_id' => $fxA['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($tokenB, $tenantB)->getJson('/api/screening')
            ->assertOk()
            ->assertJsonMissing(['id' => $screeningA]);

        $this->tenantRequest($tokenB, $tenantB)->getJson("/api/screening/{$screeningA}")->assertStatus(404);

        $this->tenantRequest($tokenB, $tenantB)
            ->putJson("/api/screening/{$screeningA}/answer", ['answers' => []])
            ->assertStatus(404);
    }

    public function test_screening_cannot_reference_a_foreign_tenant_visit(): void
    {
        $this->seedPlatformRegistry();
        $tenantA = $this->makeTenant('Organization A');
        $tenantB = $this->makeTenant('Organization B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);
        $tokenA = $this->administratorToken($tenantA);

        $fxB = $this->inTenant($tenantB, fn () => $this->makeChildVisit(570));

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/screening', ['visit_id' => $fxB['visit']->id])
            ->assertStatus(422);
    }
}
