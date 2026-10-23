<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\FollowUp\Listeners\CreateFollowUpsFromScreening;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Tests\TestCase;

/**
 * REQ-FUP-002 -- full color matrix, due dates, and atomicity.
 */
final class FollowUpCreationFromScreeningTest extends TestCase
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

    public function test_red_creates_specialist_referral_and_confirmatory_test(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $this->inTenant($tenant, function () use ($fx) {
            $followUps = FollowUp::where('child_id', $fx['child']->id)->get();
            $this->assertCount(2, $followUps);

            $types = $followUps->pluck('follow_up_type_id')->all();
            $this->assertContains(FollowUpType::SPECIALIST_REFERRAL, $types);
            $this->assertContains(FollowUpType::CONFIRMATORY_TEST, $types);
            $this->assertTrue($followUps->every(fn (FollowUp $f) => $f->follow_up_status_id === FollowUpStatus::PENDING));

            $expectedDue = Carbon::parse($fx['visit']->visit_date)->addDays(30)->toDateString();
            $this->assertTrue($followUps->every(fn (FollowUp $f) => $f->due_date->toDateString() === $expectedDue));
        });
    }

    public function test_first_yellow_creates_only_the_reassessment(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($version));

        $this->inTenant($tenant, function () use ($fx) {
            $followUps = FollowUp::where('child_id', $fx['child']->id)->get();
            $this->assertCount(1, $followUps);
            $this->assertSame(FollowUpType::REASSESS_3_MONTHS, $followUps->first()->follow_up_type_id);

            $expectedDue = Carbon::parse($fx['visit']->visit_date)->addMonths(3)->toDateString();
            $this->assertSame($expectedDue, $followUps->first()->due_date->toDateString());
        });
    }

    public function test_recurrent_yellow_escalates_to_referral(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($version));

        $visit2 = $this->inTenant($tenant, fn () => $this->makeVisitFor($fx['child'], now()->addDays(10)->toDateString()));
        $this->applyAndComplete($token, $tenant, $visit2, $this->g10YellowAnswers($version));

        $this->inTenant($tenant, function () use ($fx) {
            $openReassess = FollowUp::where('child_id', $fx['child']->id)
                ->where('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS)
                ->where('follow_up_status_id', FollowUpStatus::PENDING)
                ->count();
            $this->assertSame(1, $openReassess, 'The second yellow closes the first reassessment and opens a new one.');

            $referrals = FollowUp::where('child_id', $fx['child']->id)
                ->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)
                ->count();
            $this->assertSame(1, $referrals);
        });
    }

    public function test_green_creates_nothing(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10GreenAnswers($version));

        $this->inTenant($tenant, function () use ($fx) {
            $this->assertSame(0, FollowUp::where('child_id', $fx['child']->id)->count());
        });
    }

    /**
     * design D1 -- a listener failure must roll back the WHOLE completion
     * transaction: a completed screening never exists without its conduct.
     */
    public function test_forced_listener_failure_rolls_back_the_completion(): void
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

        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->app->bind(CreateFollowUpsFromScreening::class, fn () => new class
        {
            public function handle($event): void
            {
                throw new \RuntimeException('Forced listener failure for the atomicity test.');
            }
        });

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/complete")
            ->assertStatus(500);

        $this->inTenant($tenant, function () use ($screeningId) {
            $screening = Screening::findOrFail($screeningId);
            $this->assertSame(ScreeningStatus::SCORED, $screening->screening_status_id);
            $this->assertNull($screening->completed_at);
            $this->assertSame(0, FollowUp::where('screening_id', $screeningId)->count());
        });
    }
}
