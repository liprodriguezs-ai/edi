<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Models\Referral;
use Modules\FollowUp\Models\ReferralSpecialty;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Tests\TestCase;

/**
 * REQ-FUP-007.
 */
final class ReferralTest extends TestCase
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

    public function test_type_gate_rejects_a_non_specialist_referral_follow_up(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($version));

        [$reassessmentId, $specialtyId] = $this->inTenant($tenant, fn () => [
            FollowUp::where('child_id', $fx['child']->id)->where('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS)->firstOrFail()->id,
            ReferralSpecialty::firstOrFail()->id,
        ]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/referral', [
                'follow_up_id' => $reassessmentId,
                'referral_specialty_id' => $specialtyId,
                'referred_at' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FOLLOW_UP_TYPE_MISMATCH');

        $this->inTenant($tenant, function () use ($reassessmentId): void {
            $this->assertSame(0, Referral::where('follow_up_id', $reassessmentId)->count());
        });
    }

    public function test_referral_marks_the_follow_up_in_progress(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        [$referralFollowUpId, $specialtyId] = $this->inTenant($tenant, fn () => [
            FollowUp::where('screening_id', $screeningId)->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)->firstOrFail()->id,
            ReferralSpecialty::firstOrFail()->id,
        ]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/referral', [
                'follow_up_id' => $referralFollowUpId,
                'referral_specialty_id' => $specialtyId,
                'referred_at' => now()->toDateString(),
                'institution' => 'Hospital General',
            ])
            ->assertCreated();

        $this->inTenant($tenant, function () use ($referralFollowUpId): void {
            $this->assertSame(FollowUpStatus::IN_PROGRESS, FollowUp::findOrFail($referralFollowUpId)->follow_up_status_id);
        });
    }

    public function test_multiple_referrals_per_follow_up_are_allowed(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        [$referralFollowUpId, $specialtyIds] = $this->inTenant($tenant, fn () => [
            FollowUp::where('screening_id', $screeningId)->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)->firstOrFail()->id,
            ReferralSpecialty::limit(2)->pluck('id')->all(),
        ]);

        foreach ($specialtyIds as $specialtyId) {
            $this->tenantRequest($token, $tenant)
                ->postJson('/api/referral', [
                    'follow_up_id' => $referralFollowUpId,
                    'referral_specialty_id' => $specialtyId,
                    'referred_at' => now()->toDateString(),
                ])
                ->assertCreated();
        }

        $this->inTenant($tenant, function () use ($referralFollowUpId): void {
            $this->assertSame(2, Referral::where('follow_up_id', $referralFollowUpId)->count());
        });
    }

    public function test_referral_on_a_terminal_follow_up_is_frozen(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        [$referralFollowUpId, $specialtyId] = $this->inTenant($tenant, fn () => [
            FollowUp::where('screening_id', $screeningId)->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)->firstOrFail()->id,
            ReferralSpecialty::firstOrFail()->id,
        ]);

        $this->tenantRequest($token, $tenant)->postJson("/api/follow_up/{$referralFollowUpId}/cancel", ['reason' => 'x'])->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/referral', [
                'follow_up_id' => $referralFollowUpId,
                'referral_specialty_id' => $specialtyId,
                'referred_at' => now()->toDateString(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'FOLLOW_UP_FROZEN');
    }
}
