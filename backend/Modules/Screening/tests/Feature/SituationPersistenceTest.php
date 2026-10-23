<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Services\Scoring\Values\SituationKey;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-012 — situation is computed against a frozen previous result.
 */
final class SituationPersistenceTest extends TestCase
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

    /**
     * @return array{child: Child, healthCenter: HealthCenter, practitioner: PractitionerProfile, birthDate: Carbon}
     */
    private function makeChild(): array
    {
        $healthCenter = HealthCenter::factory()->create(['municipality_id' => HealthCenterFactory::defaultMunicipality()->id]);
        $birthDate = now()->subDays(650)->startOfDay();
        $child = Child::factory()->create(['health_center_id' => $healthCenter->id, 'birth_date' => $birthDate->format('Y-m-d')]);
        $practitioner = PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);

        return compact('child', 'healthCenter', 'practitioner', 'birthDate');
    }

    private function makeVisitAtAge(array $fx, int $ageDays): Visit
    {
        return Visit::factory()->create([
            'child_id' => $fx['child']->id,
            'health_center_id' => $fx['healthCenter']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'visit_date' => $fx['birthDate']->copy()->addDays($ageDays)->format('Y-m-d'),
        ]);
    }

    private function scoreAndComplete(string $token, $tenant, string $visitId, array $answers): string
    {
        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $visitId])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        return $screeningId;
    }

    public function test_first_time_worsened_and_unchanged_share_sis_code_six_for_worsened_and_unchanged(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChild());

        $visit1 = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 600));
        $green = $this->g10GreenAnswers($version);
        $screening1 = $this->scoreAndComplete($token, $tenant, $visit1->id, $green);

        $this->tenantRequest($token, $tenant)->getJson("/api/screening/{$screening1}")
            ->assertJsonPath('data.situation.key', SituationKey::FIRST_TIME_GREEN)
            ->assertJsonPath('data.situation.sis_code', 1);

        // Second visit, red -> worsened (green -> red), sis_code 6.
        $visit2 = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 620));
        $red = $this->g10RedAnswers($version);
        $screening2 = $this->scoreAndComplete($token, $tenant, $visit2->id, $red);

        $response2 = $this->tenantRequest($token, $tenant)->getJson("/api/screening/{$screening2}");
        $response2->assertJsonPath('data.situation.key', SituationKey::WORSENED);
        $response2->assertJsonPath('data.situation.sis_code', 6);
        $response2->assertJsonPath('data.previous_result_color_id', ResultColor::GREEN);

        // Third visit, red again -> unchanged (red -> red), ALSO sis_code 6
        // but a DIFFERENT situation row than worsened.
        $visit3 = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 640));
        $screening3 = $this->scoreAndComplete($token, $tenant, $visit3->id, $red);

        $response3 = $this->tenantRequest($token, $tenant)->getJson("/api/screening/{$screening3}");
        $response3->assertJsonPath('data.situation.key', SituationKey::UNCHANGED);
        $response3->assertJsonPath('data.situation.sis_code', 6);

        $this->assertNotSame($response2->json('data.situation_id'), $response3->json('data.situation_id'));
    }

    public function test_previous_is_frozen_at_first_score_and_rescore_reuses_it(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChild());

        // Draft A created first (later visit_date), not yet scored.
        $visitA = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 620));
        $screeningA = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $visitA->id])
            ->assertCreated()
            ->json('data.id');
        $green = $this->g10GreenAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningA}/answer", ['answers' => $green])->assertOk();

        // Sibling B (EARLIER visit_date) is created, scored, and completed
        // BEFORE A is scored.
        $visitB = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 600));
        $screeningB = $this->scoreAndComplete($token, $tenant, $visitB->id, $green);

        // Now A scores -- resolution happens NOW, at score time.
        $scoreA = $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningA}/score")->assertOk();
        $this->assertSame($screeningB, $scoreA->json('data.previous_screening_id'));

        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningA}/complete")->assertOk();

        // A LATER sibling C (even closer, still earlier than A) appears after
        // A already scored -- must NOT change A's frozen previous_screening_id.
        $visitC = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 610));
        $this->scoreAndComplete($token, $tenant, $visitC->id, $green);

        $token2 = $this->administratorToken($tenant);

        $rescore = $this->tenantRequest($token2, $tenant)
            ->postJson("/api/screening/{$screeningA}/rescore", ['authoritative' => true, 'reason' => 'Auditoría de calidad'])
            ->assertOk();

        $this->assertSame($screeningB, $rescore->json('data.previous_screening_id'));
    }

    public function test_invalidated_screening_is_excluded_from_previous_resolution(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChild());
        $green = $this->g10GreenAnswers($version);

        $visit1 = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 600));
        $screening1 = $this->scoreAndComplete($token, $tenant, $visit1->id, $green);
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screening1}/invalidate", ['reason' => 'Error de captura'])
            ->assertOk();

        $visit2 = $this->inTenant($tenant, fn () => $this->makeVisitAtAge($fx, 620));
        $screening2 = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $visit2->id])
            ->assertCreated()
            ->json('data.id');
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screening2}/answer", ['answers' => $green])->assertOk();
        $score2 = $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screening2}/score")->assertOk();

        $this->assertNull($score2->json('data.previous_screening_id'));
        $score2->assertJsonPath('data.situation.key', SituationKey::FIRST_TIME_GREEN);
    }
}
