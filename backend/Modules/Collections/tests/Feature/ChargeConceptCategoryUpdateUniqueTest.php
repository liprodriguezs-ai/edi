<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for ChargeConceptCategoryUpdateRequest's tenant-scoped unique
 * "name" rule (plan 038).
 *
 * Covers:
 *  - Updating a category keeping its OWN name → 200 (->ignore must not flag the
 *    row against itself).
 *  - Renaming a category to a SIBLING's name in the same tenant → 422 (clean
 *    validation error instead of the DB unique-constraint 500).
 *
 * NOTE: each test seeds exactly ONE tenant. A second CatalogSeeder::run() in the
 * same process collides on the central chat_channel_type fixed UUIDs (pre-existing
 * defect, CatalogSeeder.php:402), so we avoid twoTenants()/double-seed here.
 */
final class ChargeConceptCategoryUpdateUniqueTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function singleTenant(): array
    {
        $tenant = $this->makeTenant('Cluster '.uniqid());
        $this->seedTenant($tenant);
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        return [$tenant, $token];
    }

    private function makeCategory(Tenant $tenant, string $name): ChargeConceptCategory
    {
        return $this->inTenant($tenant, fn (): ChargeConceptCategory => ChargeConceptCategory::create([
            'name' => $name,
            'order' => 0,
            'is_active' => true,
        ]));
    }

    public function test_updating_category_with_its_own_name_returns_200(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $category = $this->makeCategory($tenant, 'Mantenimiento '.uniqid());

        $response = $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_concept_category/'.$category->id, [
                'name' => $category->name,
            ]);

        $response->assertOk();
    }

    public function test_renaming_to_sibling_name_in_same_tenant_returns_422(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $existing = $this->makeCategory($tenant, 'Agua '.uniqid());
        $target = $this->makeCategory($tenant, 'Seguridad '.uniqid());

        $response = $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_concept_category/'.$target->id, [
                'name' => $existing->name,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }
}
