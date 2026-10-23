<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Models\ScreeningFinding;
use Modules\Screening\Models\ScreeningRiskFactorAnswer;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-008 — plausibility: out-of-range perinatal input is absent
 * (NULL/"se ignora"), never coerced to zero/no; somatometry captured on the
 * screening itself is validated at the door.
 */
final class PlausibilityTest extends TestCase
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

    public function test_legacy_style_garbage_birth_weight_derives_nothing(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        // 10 g is below the plausible floor (400-7000). gestational_age_weeks
        // is pinned >=37 so no corrected-age substitution moves the applied
        // group away from G10 (unrelated to this test's assertion).
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570, ['birth_weight_grams' => 10, 'gestational_age_weeks' => 40]));

        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $frb4Id = $this->inTenant($tenant, fn () => $this->riskFactorId($version, 'FRB4'));

        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk();

        $response = $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        // Neither answered manually nor auto-derived -> risk_factor_count does
        // not count FRB4, and no row exists for it (never answered at all).
        $this->inTenant($tenant, function () use ($screeningId, $frb4Id) {
            $this->assertSame(0, ScreeningRiskFactorAnswer::where('screening_id', $screeningId)->where('risk_factor_id', $frb4Id)->count());

            $finding = ScreeningFinding::where('screening_id', $screeningId)
                ->whereHas('finding_type', fn ($q) => $q->where('key', ScoringFinding::UNKNOWN_PERINATAL_INPUT))
                ->get();
            $this->assertGreaterThan(0, $finding->count());
        });
    }

    public function test_implausible_weight_rejected_at_the_door(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id, 'weight_grams' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weight_grams']);
    }
}
