<?php

declare(strict_types=1);

namespace Modules\Clinic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CL-003 / REQ-XC-001 coverage for practitioner_profile.
 */
final class PractitionerProfileApiTest extends TestCase
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

    private function makeHealthCenter(): HealthCenter
    {
        return HealthCenter::factory()->create([
            'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
        ]);
    }

    public function test_one_profile_per_user_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('test', ['*'])->plainTextToken;
        $practitioner = $this->makeUser($tenant);

        $tenant->run(function () use ($practitioner): void {
            PractitionerProfile::factory()->create([
                'user_id' => $practitioner->id,
                'health_center_id' => $this->makeHealthCenter()->id,
            ]);
        });

        $healthCenter = $this->inTenant($tenant, fn () => $this->makeHealthCenter());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/practitioner_profile', [
                'user_id' => $practitioner->id,
                'license_number' => '12345678',
                'specialty' => 'Enfermería',
                'health_center_id' => $healthCenter->id,
            ])
            ->assertStatus(422);
    }

    public function test_user_must_belong_to_the_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $outsiderUser = $this->makeUser($tenantB);
        $healthCenter = $this->inTenant($tenantA, fn () => $this->makeHealthCenter());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/practitioner_profile', [
                'user_id' => $outsiderUser->id,
                'license_number' => '87654321',
                'specialty' => 'Pediatría',
                'health_center_id' => $healthCenter->id,
            ])
            ->assertStatus(422);
    }

    public function test_health_center_must_belong_to_the_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $healthCenterB = $this->inTenant($tenantB, fn () => $this->makeHealthCenter());
        $practitioner = $this->makeUser($tenantA);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/practitioner_profile', [
                'user_id' => $practitioner->id,
                'license_number' => '11223344',
                'specialty' => 'Pediatría',
                'health_center_id' => $healthCenterB->id,
            ])
            ->assertStatus(422);
    }
}
