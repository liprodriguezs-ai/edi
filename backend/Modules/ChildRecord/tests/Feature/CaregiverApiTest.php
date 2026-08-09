<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\EducationLevel;
use Modules\ChildRecord\Models\Occupation;
use Modules\ChildRecord\Models\PopulationType;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CH-004 coverage.
 */
final class CaregiverApiTest extends TestCase
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

    public function test_tenant_catalog_row_from_another_tenant_rejected(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $occupationB = $this->inTenant($tenantB, fn () => Occupation::factory()->create());
        $educationA = $this->inTenant($tenantA, fn () => EducationLevel::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/caregiver', [
                'first_name' => 'Juana',
                'last_name_paternal' => 'Pérez',
                'education_level_id' => $educationA->id,
                'occupation_id' => $occupationB->id,
                'population_type_id' => PopulationType::URBAN,
                'phone' => '5512345678',
            ])
            ->assertStatus(422);
    }

    public function test_create_caregiver_happy_path(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $education = $this->inTenant($tenant, fn () => EducationLevel::factory()->create());
        $occupation = $this->inTenant($tenant, fn () => Occupation::factory()->create());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/caregiver', [
                'first_name' => 'Juana',
                'last_name_paternal' => 'Pérez',
                'education_level_id' => $education->id,
                'occupation_id' => $occupation->id,
                'population_type_id' => PopulationType::URBAN,
                'phone' => '5512345678',
            ])
            ->assertCreated()
            ->assertJsonPath('data.last_name_maternal', null);
    }
}
