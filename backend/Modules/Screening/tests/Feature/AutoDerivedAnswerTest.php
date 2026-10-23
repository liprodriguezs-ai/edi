<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Models\ScreeningAnswer;
use Modules\Screening\Models\ScreeningFinding;
use Modules\Screening\Models\ScreeningRiskFactorAnswer;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-007/008 — auto-derived answers arrive locked and override; a NULL
 * perinatal value stays manually answerable and derives nothing (D-14).
 */
final class AutoDerivedAnswerTest extends TestCase
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

    public function test_known_head_circumference_locks_and_overrides_a_manual_red_answer(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570, null, ['sex_id' => Sex::MALE]));

        $create = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id, 'head_circumference_mm' => 470])
            ->assertCreated();
        $screeningId = $create->json('data.id');

        $answers = $this->g10RedAnswers($version);
        foreach ($answers as &$a) {
            if ($a === $this->answerFor($version, 'G10', 'EN10.3', 'NO')) {
                $a = $this->answerFor($version, 'G10', 'EN10.3', 'SI');
            }
        }
        unset($a);

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->inTenant($tenant, function () use ($screeningId, $version) {
            $enHcItem = $this->answerFor($version, 'G10', 'EN10.3', 'NO');
            $stored = ScreeningAnswer::where('screening_id', $screeningId)
                ->where('screening_item_id', $enHcItem['screening_item_id'])
                ->firstOrFail();

            $this->assertTrue((bool) $stored->is_auto_derived);
            $this->assertSame(ResultColor::GREEN, $stored->result_color_id);

            $finding = ScreeningFinding::where('screening_id', $screeningId)
                ->whereHas('finding_type', fn ($q) => $q->where('key', ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL))
                ->first();
            $this->assertNotNull($finding);
        });
    }

    public function test_unknown_birth_weight_leaves_frb4_manual_and_records_unknown_perinatal_input(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        // No birth_record at all -> every perinatal field NULL.
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $create = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated();
        $screeningId = $create->json('data.id');

        $frb4Id = $this->inTenant($tenant, fn () => $this->riskFactorId($version, 'FRB4'));

        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", [
                'answers' => $answers,
                'risk_factor_answers' => [['risk_factor_id' => $frb4Id, 'is_present' => true]],
            ])
            ->assertOk();

        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->inTenant($tenant, function () use ($screeningId, $frb4Id) {
            $stored = ScreeningRiskFactorAnswer::where('screening_id', $screeningId)
                ->where('risk_factor_id', $frb4Id)
                ->firstOrFail();

            $this->assertTrue((bool) $stored->is_present);
            $this->assertFalse((bool) $stored->is_auto_derived);

            $finding = ScreeningFinding::where('screening_id', $screeningId)
                ->whereHas('finding_type', fn ($q) => $q->where('key', ScoringFinding::UNKNOWN_PERINATAL_INPUT))
                ->get();
            $this->assertGreaterThan(0, $finding->count());
        });
    }
}
