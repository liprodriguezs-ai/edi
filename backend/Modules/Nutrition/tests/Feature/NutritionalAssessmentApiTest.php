<?php

declare(strict_types=1);

namespace Modules\Nutrition\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Models\NutritionalStatus;
use Modules\Nutrition\Tests\Support\WithNutritionFixtures;
use Tests\TestCase;

/**
 * S5 -- nutritional_assessment API: server-computed statuses, tenant
 * isolation, permissions, validation and the classify preview.
 */
final class NutritionalAssessmentApiTest extends TestCase
{
    use RefreshDatabase;
    use WithNutritionFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_store_computes_the_statuses_on_the_server_and_ignores_client_status_ids(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', [
                'child_id' => $fx['child']->id,
                'measured_on' => '2025-01-10',
                'weight_grams' => 9700,
                'height_mm' => 810,
                'notes' => 'Control mensual',
                'weight_for_height_status_id' => NutritionalStatus::OBESITY,
                'weight_for_age_status_id' => NutritionalStatus::NORMAL_WEIGHT,
                'height_for_age_status_id' => NutritionalStatus::TALL_STATURE,
                'age_months' => 3,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.age_months', 24)
            ->assertJsonPath('data.weight_for_height_status.key', 'mild_malnutrition')
            ->assertJsonPath('data.weight_for_age_status.key', 'moderate_malnutrition')
            ->assertJsonPath('data.height_for_age_status.key', 'short_stature');

        $stored = $this->inTenant($tenant, fn () => NutritionalAssessment::findOrFail($response->json('data.id')));
        $this->assertSame(NutritionalStatus::MILD_MALNUTRITION, $stored->weight_for_height_status_id);
        $this->assertSame(NutritionalStatus::MODERATE_MALNUTRITION, $stored->weight_for_age_status_id);
        $this->assertSame(NutritionalStatus::SHORT_STATURE, $stored->height_for_age_status_id);
        $this->assertSame(24, $stored->age_months);
        $this->assertSame($tenant->id, $stored->tenant_id);
        $this->assertNull($stored->screening_id);
    }

    public function test_update_recomputes_the_statuses(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        $id = $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', [
                'child_id' => $fx['child']->id,
                'measured_on' => '2025-01-10',
                'weight_grams' => 9700,
                'height_mm' => 810,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/nutritional_assessment/{$id}", ['weight_grams' => 12000, 'height_mm' => 850])
            ->assertOk()
            ->assertJsonPath('data.weight_for_height_status.key', 'normal_weight')
            ->assertJsonPath('data.weight_for_age_status.key', 'normal_weight')
            ->assertJsonPath('data.height_for_age_status.key', 'normal_stature');
    }

    public function test_validation_rejects_impossible_measurements(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());
        $childId = $fx['child']->id;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', ['child_id' => $childId, 'measured_on' => '2025-01-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weight_grams', 'height_mm']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', ['child_id' => $childId, 'measured_on' => '2025-01-10', 'weight_grams' => 499])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weight_grams']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', ['child_id' => $childId, 'measured_on' => '2025-01-10', 'height_mm' => 1401])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['height_mm']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', ['child_id' => $childId, 'measured_on' => '2022-12-31', 'weight_grams' => 3000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measured_on']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', ['child_id' => $childId, 'measured_on' => now()->addDays(3)->toDateString(), 'weight_grams' => 3000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measured_on']);

        $this->assertSame(0, $this->inTenant($tenant, fn () => NutritionalAssessment::count()));
    }

    public function test_another_tenant_can_neither_read_nor_target_the_assessment(): void
    {
        $this->seedNutritionPlatform();
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);
        $tokenA = $this->administratorToken($tenantA);
        $tokenB = $this->administratorToken($tenantB);
        $fx = $this->inTenant($tenantA, fn () => $this->makeMaleChildAt24Months());

        $id = $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/nutritional_assessment', [
                'child_id' => $fx['child']->id,
                'measured_on' => '2025-01-10',
                'weight_grams' => 9700,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($tokenB, $tenantB)
            ->getJson("/api/nutritional_assessment/{$id}")
            ->assertNotFound();

        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/nutritional_assessment/search', [])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // B cannot create an assessment for A's child.
        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/nutritional_assessment', [
                'child_id' => $fx['child']->id,
                'measured_on' => '2025-01-10',
                'weight_grams' => 9700,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['child_id']);

        $this->tenantRequest($tokenB, $tenantB)
            ->getJson('/api/nutritional_assessment/classify?'.http_build_query([
                'child_id' => $fx['child']->id, 'measured_on' => '2025-01-10', 'weight_grams' => 9700,
            ]))
            ->assertStatus(422);
    }

    public function test_without_permission_the_endpoints_are_forbidden(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('none', [])->plainTextToken;
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment/search', [])
            ->assertForbidden();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment', [
                'child_id' => $fx['child']->id, 'measured_on' => '2025-01-10', 'weight_grams' => 9700,
            ])
            ->assertForbidden();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/nutritional_assessment/classify?'.http_build_query([
                'child_id' => $fx['child']->id, 'measured_on' => '2025-01-10', 'weight_grams' => 9700,
            ]))
            ->assertForbidden();
    }

    public function test_classify_previews_the_statuses_without_persisting(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/nutritional_assessment/classify?'.http_build_query([
                'child_id' => $fx['child']->id,
                'measured_on' => '2025-01-10',
                'weight_grams' => 9700,
                'height_mm' => 810,
            ]))
            ->assertOk()
            ->assertJsonPath('data.age_months', 24)
            ->assertJsonPath('data.weight_for_height_status.key', 'mild_malnutrition')
            ->assertJsonPath('data.weight_for_height_status.name', 'Desnutrición leve')
            ->assertJsonPath('data.weight_for_age_status.key', 'moderate_malnutrition')
            ->assertJsonPath('data.height_for_age_status.key', 'short_stature');

        $this->assertSame(0, $this->inTenant($tenant, fn () => NutritionalAssessment::count()));
    }

    public function test_search_filters_by_child(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());
        $other = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        foreach ([$fx['child']->id, $other['child']->id] as $childId) {
            $this->tenantRequest($token, $tenant)
                ->postJson('/api/nutritional_assessment', [
                    'child_id' => $childId, 'measured_on' => '2025-01-10', 'weight_grams' => 9700,
                ])
                ->assertCreated();
        }

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/nutritional_assessment/search', [
                'filters' => [['field' => 'child_id', 'operator' => '=', 'value' => $fx['child']->id]],
                'sort' => [['field' => 'measured_on', 'direction' => 'desc']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.child_id', $fx['child']->id);
    }
}
