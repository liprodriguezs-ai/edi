<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Models\ScreeningAnswer;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * The evaluator learns that an axis needs the previous group's items while
 * capturing, not only when scoring: every answer save and the item bank
 * report which axes require them. Alternate answers that stop applying are
 * discarded so they never count towards the axis result.
 */
final class AlternateRequirementTest extends TestCase
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
     * @return array{0: string, 1: string, 2: mixed, 3: InstrumentVersion}
     */
    private function draft(): array
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        return [$screeningId, $token, $tenant, $version];
    }

    public function test_saving_an_all_yellow_axis_reports_its_alternate_items(): void
    {
        [$screeningId, $token, $tenant, $version] = $this->draft();

        $response = $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $this->g10AllYellowMfAnswers($version)])
            ->assertOk();

        $requirements = $response->json('alternate_requirements');
        $this->assertCount(1, $requirements);
        $this->assertSame('MF', $requirements[0]['code']);
        $this->assertNotNull($requirements[0]['development_area_id']);
        $this->assertSame(['MF9.1', 'MF9.2', 'MF9.3'], array_column($requirements[0]['items'], 'code'));
        $this->assertEqualsCanonicalizing(['SI', 'NO'], array_column($requirements[0]['items'][0]['options'], 'label'));
        $this->assertTrue($requirements[0]['items'][0]['is_alternate']);
    }

    public function test_no_axis_requires_alternates_while_an_item_is_achieved_or_unanswered(): void
    {
        [$screeningId, $token, $tenant, $version] = $this->draft();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $this->g10RedAnswers($version)])
            ->assertOk()
            ->assertJsonPath('alternate_requirements', []);

        [$otherId, $otherToken, $otherTenant, $otherVersion] = $this->draft();
        $this->tenantRequest($otherToken, $otherTenant)
            ->putJson("/api/screening/{$otherId}/answer", ['answers' => [
                $this->answerFor($otherVersion, 'G10', 'MF10.1', 'NO'),
                $this->answerFor($otherVersion, 'G10', 'MF10.2', 'NO'),
            ]])
            ->assertOk()
            ->assertJsonPath('alternate_requirements', []);
    }

    public function test_the_item_bank_reports_pending_alternate_requirements_on_resume(): void
    {
        [$screeningId, $token, $tenant, $version] = $this->draft();
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $this->g10AllYellowMfAnswers($version)])
            ->assertOk();

        $requirements = $this->tenantRequest($token, $tenant)
            ->getJson("/api/screening/{$screeningId}/items")
            ->assertOk()
            ->json('alternate_requirements');

        $this->assertSame(['MF'], array_column($requirements, 'code'));
    }

    public function test_alternate_answers_are_discarded_when_the_axis_no_longer_requires_them(): void
    {
        [$screeningId, $token, $tenant, $version] = $this->draft();
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [
                ...$this->g10AllYellowMfAnswers($version),
                ...$this->g9aMfAlternateAnswers($version),
            ]])
            ->assertOk();

        $response = $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [
                $this->answerFor($version, 'G10', 'MF10.1', 'SI'),
            ]])
            ->assertOk()
            ->assertJsonPath('alternate_requirements', []);

        $alternateItemIds = array_column($this->g9aMfAlternateAnswers($version), 'screening_item_id');
        $this->assertSame(
            [],
            array_values(array_intersect($alternateItemIds, array_column($response->json('data.answers') ?? [], 'screening_item_id')))
        );

        $this->inTenant($tenant, function () use ($screeningId, $alternateItemIds) {
            $this->assertSame(0, ScreeningAnswer::where('screening_id', $screeningId)->whereIn('screening_item_id', $alternateItemIds)->count());
            // Soft-deleted, so the audit trail keeps what was discarded.
            $this->assertSame(3, ScreeningAnswer::withTrashed()->where('screening_id', $screeningId)->whereIn('screening_item_id', $alternateItemIds)->count());
        });
    }

    public function test_a_discarded_alternate_can_be_answered_again(): void
    {
        [$screeningId, $token, $tenant, $version] = $this->draft();
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [
                ...$this->g10AllYellowMfAnswers($version),
                ...$this->g9aMfAlternateAnswers($version),
            ]])
            ->assertOk();
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [$this->answerFor($version, 'G10', 'MF10.1', 'SI')]])
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [
                $this->answerFor($version, 'G10', 'MF10.1', 'NO'),
                ...$this->g9aMfAlternateAnswers($version),
            ]])
            ->assertOk()
            ->assertJsonCount(1, 'alternate_requirements');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertOk()
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::SCORED);
    }

    public function test_discarding_alternates_of_a_scored_screening_sends_it_back_to_draft(): void
    {
        [$screeningId, $token, $tenant, $version] = $this->draft();
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [
                ...$this->g10AllYellowMfAnswers($version),
                ...$this->g9aMfAlternateAnswers($version),
            ]])
            ->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => [$this->answerFor($version, 'G10', 'MF10.1', 'SI')]])
            ->assertOk()
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::DRAFT);
    }
}
