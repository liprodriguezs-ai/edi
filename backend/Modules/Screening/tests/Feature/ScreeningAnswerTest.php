<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Screening\Models\ScreeningAnswer;
use Modules\Screening\Models\ScreeningScoreRun;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-004 — batch idempotent answers with staleness reset.
 */
final class ScreeningAnswerTest extends TestCase
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

    private function createScreening(string $token, $tenant, int $ageDays = 570): array
    {
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit($ageDays));
        $created = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated();

        return [$created->json('data.id'), $fx];
    }

    public function test_idempotent_replay_yields_the_same_rows(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        [$screeningId] = $this->createScreening($token, $tenant);
        $answers = $this->g10RedAnswers($version);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk();

        $this->inTenant($tenant, function () use ($screeningId, $answers) {
            $this->assertSame(count($answers), ScreeningAnswer::where('screening_id', $screeningId)->count());
        });
    }

    public function test_offending_item_ids_return_422(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        [$screeningId] = $this->createScreening($token, $tenant);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", [
                'answers' => [['screening_item_id' => (string) Str::uuid7(), 'screening_item_option_id' => (string) Str::uuid7()]],
            ])
            ->assertStatus(422);
    }

    public function test_mutation_while_scored_reverts_to_draft_and_clears_header(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        [$screeningId] = $this->createScreening($token, $tenant);
        $answers = $this->g10RedAnswers($version);

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        // Flip EN10.1 from SI(red) to NO(green) -- changes the effective input.
        $changed = $this->answerFor($version, 'G10', 'EN10.1', 'NO');
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [$changed]])
            ->assertOk()
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::DRAFT)
            ->assertJsonPath('data.result_color_id', null);

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(1, ScreeningScoreRun::where('screening_id', $screeningId)->count());
        });
    }

    public function test_no_op_replay_while_scored_stays_scored(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        [$screeningId] = $this->createScreening($token, $tenant);
        $answers = $this->g10RedAnswers($version);

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::SCORED);
    }

    public function test_completed_screening_rejects_answer_changes(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        [$screeningId] = $this->createScreening($token, $tenant);
        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SCREENING_FROZEN');
    }
}
