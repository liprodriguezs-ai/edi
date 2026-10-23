<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Models\Screening;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-002/007/008 — draft creation resolves everything server-side.
 */
final class ScreeningCreateTest extends TestCase
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

    public function test_draft_created_with_server_resolved_group_and_ages(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated();

        $response->assertJsonPath('data.chronological_age_days', 570);
        $this->assertNotNull($response->json('data.age_group_id'));
        $this->assertNotNull($response->json('data.applied_age_group_id'));
        $this->assertSame($response->json('data.age_group_id'), $response->json('data.applied_age_group_id'));
        $this->assertNotEmpty($response->json('item_bank.areas'));

        $this->inTenant($tenant, function () use ($fx) {
            $this->assertSame(1, Screening::where('visit_id', $fx['visit']->id)->count());
        });
    }

    public function test_premature_substitution_is_stored_not_client_chosen(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        // G3 [90-119]; gestational_age_weeks=32 (<34, <37 gate) corrects to
        // 100 - (40-32)*7 = 44 days -> G1 [30-59] official group.
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(100, ['gestational_age_weeks' => 32]));

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated();

        $response->assertJsonPath('data.chronological_age_days', 100);
        $response->assertJsonPath('data.corrected_age_days', 44);
        $this->assertNotSame($response->json('data.age_group_id'), $response->json('data.applied_age_group_id'));
    }

    public function test_a_premature_baby_under_one_month_corrected_age_is_not_applicable_yet(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        // 45 days at 34 weeks: corrected age 3 days. Officially the Prueba 1 is
        // for "un mes de edad -corregida-", so it waits instead of using G1 by
        // chronological age.
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(45, ['gestational_age_weeks' => 34]));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SCREENING_NOT_APPLICABLE')
            ->assertJsonPath('message', 'La edad corregida por prematurez es de 3 días. Aplica la Prueba EDI cuando su edad corregida cumpla 1 mes (30 días).');
    }

    public function test_a_term_baby_of_forty_five_days_still_gets_group_one(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(45, ['gestational_age_weeks' => 39]));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->assertJsonPath('data.applied_age_group.code', 'G1');
    }

    public function test_out_of_range_child_persists_nothing(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(2200));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SCREENING_NOT_APPLICABLE');

        $this->inTenant($tenant, function () {
            $this->assertSame(0, Screening::count());
        });
    }

    public function test_second_create_on_same_visit_is_rejected(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $this->tenantRequest($token, $tenant)->postJson('/api/screening', ['visit_id' => $fx['visit']->id])->assertCreated();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VISIT_ALREADY_SCREENED');
    }

    public function test_item_bank_flags_head_circumference_locked_when_known(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id, 'head_circumference_mm' => 460])
            ->assertCreated();

        $enArea = collect($response->json('item_bank.areas'))->firstWhere('code', 'EN');
        $this->assertNotNull($enArea);
        $lockedItems = collect($enArea['items'])->where('locked', true);
        $this->assertGreaterThanOrEqual(1, $lockedItems->count());
        $this->assertSame('head_circumference', $lockedItems->first()['locked_reason']['auto_derivation_key']);

        // Options never carry a color.
        foreach ($enArea['items'] as $item) {
            foreach ($item['options'] as $option) {
                $this->assertArrayNotHasKey('result_color_id', $option);
                $this->assertArrayNotHasKey('color', $option);
            }
        }
    }

    public function test_implausible_somatometry_capture_is_rejected_at_the_door(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id, 'weight_grams' => 100])
            ->assertStatus(422);

        $this->inTenant($tenant, function () {
            $this->assertSame(0, Screening::count());
        });
    }
}
