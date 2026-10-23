<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Models\ScreeningAreaResult;
use Modules\Screening\Models\ScreeningScoreRun;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-005/006 — gates run before any write, transactional persist,
 * trace fidelity, determinism.
 */
final class ScreeningScoreTest extends TestCase
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

    private function createScreening(string $token, $tenant, int $ageDays = 570): string
    {
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit($ageDays));

        return $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_incomplete_answers_gate_fires_before_alternate_gate_and_persists_nothing(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $screeningId = $this->createScreening($token, $tenant);
        $answers = $this->g10AllYellowMfAnswers($version);
        array_pop($answers); // leave one own item unanswered

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertStatus(422)
            ->assertJsonPath('code', 'INCOMPLETE_ANSWERS');

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(0, ScreeningScoreRun::where('screening_id', $screeningId)->count());
            $this->assertSame(0, ScreeningAreaResult::where('screening_id', $screeningId)->count());
        });
    }

    public function test_all_yellow_axis_demands_alternate_and_persists_nothing(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $screeningId = $this->createScreening($token, $tenant);
        $answers = $this->g10AllYellowMfAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALTERNATE_ITEMS_REQUIRED');

        $areas = collect($response->json('areas'));
        $mfArea = $areas->firstWhere('code', 'MF');
        $this->assertNotNull($mfArea);
        $this->assertCount(3, $mfArea['missing_items']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/screening/{$screeningId}")
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::DRAFT);

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(0, ScreeningScoreRun::where('screening_id', $screeningId)->count());
            $this->assertSame(0, ScreeningAreaResult::where('screening_id', $screeningId)->count());
        });
    }

    public function test_answered_alternates_unlock_scoring(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $screeningId = $this->createScreening($token, $tenant);
        $answers = [...$this->g10AllYellowMfAnswers($version), ...$this->g9aMfAlternateAnswers($version)];
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertOk();

        $response->assertJsonPath('data.screening_status_id', ScreeningStatus::SCORED);

        $this->inTenant($tenant, function () use ($screeningId) {
            $mfResult = ScreeningAreaResult::where('screening_id', $screeningId)
                ->whereHas('development_area', fn ($q) => $q->where('code', 'MF'))
                ->first();
            $this->assertNotNull($mfResult);
            $this->assertSame(3, $mfResult->answered_own_count);
            $this->assertSame(3, $mfResult->answered_alternate_count);
        });
    }

    public function test_trace_is_persisted_whole_and_counters_match_the_header(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $screeningId = $this->createScreening($token, $tenant);
        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();

        $response = $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->inTenant($tenant, function () use ($screeningId, $response) {
            $run = ScreeningScoreRun::where('screening_id', $screeningId)->sole();
            $this->assertTrue($run->is_authoritative);
            $this->assertSame(ScreeningScoreRun::TRIGGER_INITIAL, $run->trigger);
            $this->assertSame($response->json('data.alert_sign_count'), $run->result_payload['alert_sign_count']);
            $this->assertSame($response->json('data.risk_factor_count'), $run->result_payload['risk_factor_count']);
            $this->assertArrayHasKey('area_results', $run->result_payload);
            $this->assertArrayHasKey('matched_global_rule_sequence', $run->result_payload);
        });
    }

    public function test_scoring_twice_without_changes_is_deterministic(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $screeningId = $this->createScreening($token, $tenant);
        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();

        $first = $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();
        $second = $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->assertSame($first->json('data.result_color_id'), $second->json('data.result_color_id'));

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(2, ScreeningScoreRun::where('screening_id', $screeningId)->count());
        });
    }
}
