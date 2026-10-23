<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Models\ReferralSpecialty;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Tests\TestCase;

/**
 * REQ-FUP-006.
 */
final class FollowUpLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WithFollowUpFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function completedRedFollowUp(string $token, $tenant): array
    {
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $followUpId = $this->inTenant($tenant, fn () => FollowUp::where('screening_id', $screeningId)
            ->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)
            ->firstOrFail()->id);

        return [$screeningId, $followUpId];
    }

    public function test_transition_matrix_start_complete_cancel(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        [, $followUpId] = $this->completedRedFollowUp($token, $tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/start")
            ->assertOk()
            ->assertJsonPath('data.follow_up_status_id', FollowUpStatus::IN_PROGRESS);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/complete")
            ->assertOk()
            ->assertJsonPath('data.follow_up_status_id', FollowUpStatus::COMPLETED);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        [, $followUpId] = $this->completedRedFollowUp($token, $tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/cancel", [])
            ->assertStatus(422);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/cancel", ['reason' => 'Ya no aplica'])
            ->assertOk()
            ->assertJsonPath('data.follow_up_status_id', FollowUpStatus::CANCELLED);
    }

    public function test_forged_status_fields_are_ignored_on_create_and_update(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $followUpId = $this->inTenant($tenant, fn () => FollowUp::where('screening_id', $screeningId)
            ->where('follow_up_type_id', FollowUpType::CONFIRMATORY_TEST)
            ->firstOrFail()->id);

        $response = $this->tenantRequest($token, $tenant)
            ->putJson("/api/follow_up/{$followUpId}", [
                'due_date' => now()->addDays(45)->toDateString(),
                'follow_up_status_id' => FollowUpStatus::COMPLETED,
                'closed_by_screening_id' => $screeningId,
                'completed_at' => now()->toDateTimeString(),
            ])
            ->assertOk();

        $this->assertNotSame(FollowUpStatus::COMPLETED, $response->json('data.follow_up_status_id'));

        $this->inTenant($tenant, function () use ($followUpId): void {
            $followUp = FollowUp::findOrFail($followUpId);
            $this->assertNull($followUp->closed_by_screening_id);
            $this->assertNull($followUp->completed_at);
        });
    }

    public function test_terminal_transitions_and_updates_are_frozen(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        [, $followUpId] = $this->completedRedFollowUp($token, $tenant);

        $this->tenantRequest($token, $tenant)->postJson("/api/follow_up/{$followUpId}/complete")->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/start")
            ->assertStatus(409)->assertJsonPath('code', 'FOLLOW_UP_FROZEN');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/complete")
            ->assertStatus(409)->assertJsonPath('code', 'FOLLOW_UP_FROZEN');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/follow_up/{$followUpId}/cancel", ['reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'FOLLOW_UP_FROZEN');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/follow_up/{$followUpId}", ['notes' => 'no debería aplicar'])
            ->assertStatus(409)->assertJsonPath('code', 'FOLLOW_UP_FROZEN');
    }

    public function test_update_whitelist_allows_only_due_date_notes_and_referral_specialty(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        [, $followUpId] = $this->completedRedFollowUp($token, $tenant);

        $specialtyId = $this->inTenant($tenant, fn () => ReferralSpecialty::firstOrFail()->id);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/follow_up/{$followUpId}", [
                'due_date' => now()->addDays(15)->toDateString(),
                'notes' => 'Nota administrativa',
                'referral_specialty_id' => $specialtyId,
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Nota administrativa')
            ->assertJsonPath('data.referral_specialty_id', $specialtyId);
    }

    public function test_manual_store_requires_a_completed_screening_of_the_same_child(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $draftId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/follow_up', [
                'screening_id' => $draftId,
                'follow_up_type_id' => FollowUpType::CONFIRMATORY_TEST,
                'due_date' => now()->addDays(30)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SCREENING_NOT_ELIGIBLE');
    }
}
