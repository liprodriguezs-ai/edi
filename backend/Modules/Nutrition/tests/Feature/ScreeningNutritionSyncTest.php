<?php

declare(strict_types=1);

namespace Modules\Nutrition\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\Visit;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Models\NutritionalStatus;
use Modules\Nutrition\Tests\Support\WithNutritionFixtures;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

/**
 * S5 -- the Prueba EDI somatometry feeds the child's nutritional history:
 * the screening observer (registered by the Nutrition module) upserts the
 * linked assessment.
 */
final class ScreeningNutritionSyncTest extends TestCase
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

    private function makeScreening(Tenant $tenant, Visit $visit, ?int $weight, ?int $height): Screening
    {
        return $this->inTenant($tenant, fn () => Screening::forceCreate([
            'visit_id' => $visit->id,
            'child_id' => $visit->child_id,
            'instrument_version_id' => (string) Str::uuid(),
            'age_group_id' => (string) Str::uuid(),
            'applied_age_group_id' => (string) Str::uuid(),
            'chronological_age_days' => 731,
            'weight_grams' => $weight,
            'height_mm' => $height,
            'screening_status_id' => ScreeningStatus::DRAFT,
        ]));
    }

    private function linkedAssessment(Tenant $tenant, Screening $screening): ?NutritionalAssessment
    {
        return $this->inTenant($tenant, fn () => NutritionalAssessment::where('screening_id', $screening->id)->first());
    }

    public function test_a_screening_with_somatometry_creates_the_linked_assessment(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        $screening = $this->makeScreening($tenant, $fx['visit'], 12000, 850);

        $assessment = $this->linkedAssessment($tenant, $screening);
        $this->assertNotNull($assessment);
        $this->assertSame($tenant->id, $assessment->tenant_id);
        $this->assertSame($fx['child']->id, $assessment->child_id);
        $this->assertSame('2025-01-10', $assessment->measured_on->toDateString());
        $this->assertSame(24, $assessment->age_months);
        $this->assertSame(12000, $assessment->weight_grams);
        $this->assertSame(850, $assessment->height_mm);
        $this->assertSame(NutritionalStatus::NORMAL_WEIGHT, $assessment->weight_for_height_status_id);
        $this->assertSame(NutritionalStatus::NORMAL_WEIGHT, $assessment->weight_for_age_status_id);
        $this->assertSame(NutritionalStatus::NORMAL_STATURE, $assessment->height_for_age_status_id);
    }

    public function test_changing_the_screening_somatometry_updates_the_assessment(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());
        $screening = $this->makeScreening($tenant, $fx['visit'], 12000, 850);

        $this->inTenant($tenant, fn () => $screening->fill(['weight_grams' => 8000])->save());

        $assessment = $this->linkedAssessment($tenant, $screening);
        $this->assertSame(8000, $assessment->weight_grams);
        $this->assertSame(NutritionalStatus::SEVERE_MALNUTRITION, $assessment->weight_for_height_status_id);
        $this->assertSame(NutritionalStatus::SEVERE_MALNUTRITION, $assessment->weight_for_age_status_id);
        $this->assertSame(1, $this->inTenant($tenant, fn () => NutritionalAssessment::count()));
    }

    public function test_clearing_both_measures_removes_the_linked_assessment(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());
        $screening = $this->makeScreening($tenant, $fx['visit'], 12000, 850);

        $this->inTenant($tenant, fn () => $screening->fill(['weight_grams' => null, 'height_mm' => null])->save());

        $this->assertNull($this->linkedAssessment($tenant, $screening));
    }

    public function test_a_screening_without_somatometry_creates_nothing(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        $screening = $this->makeScreening($tenant, $fx['visit'], null, null);
        $this->inTenant($tenant, fn () => $screening->fill(['notes' => 'sin medidas'])->save());

        $this->assertSame(0, $this->inTenant($tenant, fn () => NutritionalAssessment::count()));
    }

    public function test_a_linked_assessment_cannot_be_edited_from_the_api(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());
        $screening = $this->makeScreening($tenant, $fx['visit'], 12000, 850);
        $assessment = $this->linkedAssessment($tenant, $screening);

        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/nutritional_assessment/{$assessment->id}", ['weight_grams' => 5000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weight_grams']);

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/nutritional_assessment/{$assessment->id}")
            ->assertStatus(422);

        $this->assertSame(12000, $this->linkedAssessment($tenant, $screening)->weight_grams);
    }

    public function test_the_prueba_edi_document_shows_the_nutritional_status(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());
        $screening = $this->makeScreening($tenant, $fx['visit'], 12000, 850);

        $html = $this->inTenant($tenant, fn () => view('screening::document', ['screening' => $screening->fresh()])->render());

        $this->assertStringContainsString('Estado nutricional', $html);
        $this->assertStringContainsString('Peso para la talla', $html);
        $this->assertStringContainsString('Peso normal', $html);
        $this->assertStringContainsString('Talla normal', $html);
    }

    public function test_backfill_classifies_prueba_edi_somatometry_saved_before_the_module_existed(): void
    {
        $this->seedNutritionPlatform();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeMaleChildAt24Months());

        // Rows written before the observer existed (e.g. the legacy migration).
        $withMeasures = $this->makeScreening($tenant, $fx['visit'], 12000, 850);
        $this->inTenant($tenant, fn () => NutritionalAssessment::query()->forceDelete());
        $this->assertNull($this->linkedAssessment($tenant, $withMeasures));

        $this->artisan('nutrition:backfill')->assertSuccessful();
        $this->artisan('nutrition:backfill')->assertSuccessful();

        $assessment = $this->linkedAssessment($tenant, $withMeasures);
        $this->assertNotNull($assessment);
        $this->assertSame(NutritionalStatus::NORMAL_WEIGHT, $assessment->weight_for_height_status_id);
        $this->assertSame(1, $this->inTenant($tenant, fn () => NutritionalAssessment::count()));
    }
}
