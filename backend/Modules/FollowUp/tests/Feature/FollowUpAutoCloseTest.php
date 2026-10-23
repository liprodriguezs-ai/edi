<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Tests\TestCase;

/**
 * REQ-FUP-004.
 */
final class FollowUpAutoCloseTest extends TestCase
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

    public function test_the_3_month_loop_closes_on_reapplication(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($version));

        $reassessmentId = $this->inTenant($tenant, fn () => FollowUp::where('child_id', $fx['child']->id)
            ->where('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS)
            ->firstOrFail()->id);

        $visit2 = $this->inTenant($tenant, fn () => $this->makeVisitFor($fx['child'], now()->addDays(10)->toDateString()));
        $screening2Id = $this->applyAndComplete($token, $tenant, $visit2, $this->g10GreenAnswers($version));

        $this->inTenant($tenant, function () use ($reassessmentId, $screening2Id): void {
            $reassessment = FollowUp::findOrFail($reassessmentId);
            $this->assertSame(FollowUpStatus::COMPLETED, $reassessment->follow_up_status_id);
            $this->assertSame($screening2Id, $reassessment->closed_by_screening_id);
            $this->assertNotNull($reassessment->completed_at);
        });
    }

    public function test_a_backfilled_older_screening_does_not_close(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $originVisit = $this->inTenant($tenant, fn () => $this->makeVisitFor($fx['child'], now()->addDays(40)->toDateString()));
        $this->applyAndComplete($token, $tenant, $originVisit, $this->g10YellowAnswers($version));

        $reassessmentId = $this->inTenant($tenant, fn () => FollowUp::where('child_id', $fx['child']->id)
            ->where('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS)
            ->firstOrFail()->id);

        // Backfilled: visit_date is BEFORE the reassessment's origin visit.
        $backfilledVisit = $this->inTenant($tenant, fn () => $this->makeVisitFor($fx['child'], now()->addDays(10)->toDateString()));
        $this->applyAndComplete($token, $tenant, $backfilledVisit, $this->g10GreenAnswers($version));

        $this->inTenant($tenant, function () use ($reassessmentId): void {
            $reassessment = FollowUp::findOrFail($reassessmentId);
            $this->assertSame(FollowUpStatus::PENDING, $reassessment->follow_up_status_id);
            $this->assertNull($reassessment->closed_by_screening_id);
        });
    }

    public function test_referrals_never_auto_close(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $referralId = $this->inTenant($tenant, fn () => FollowUp::where('child_id', $fx['child']->id)
            ->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)
            ->firstOrFail()->id);

        $visit2 = $this->inTenant($tenant, fn () => $this->makeVisitFor($fx['child'], now()->addDays(10)->toDateString()));
        $this->applyAndComplete($token, $tenant, $visit2, $this->g10GreenAnswers($version));

        $this->inTenant($tenant, function () use ($referralId): void {
            $referral = FollowUp::findOrFail($referralId);
            $this->assertSame(FollowUpStatus::PENDING, $referral->follow_up_status_id);
            $this->assertNull($referral->closed_by_screening_id);
        });
    }

    public function test_other_types_and_children_are_untouched(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fxA = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fxA['visit'], $this->g10YellowAnswers($version));
        $reassessmentAId = $this->inTenant($tenant, fn () => FollowUp::where('child_id', $fxA['child']->id)->firstOrFail()->id);

        // Child B: completing a screening for a DIFFERENT child must never
        // touch child A's open reassessment.
        $fxB = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fxB['visit'], $this->g10GreenAnswers($version));

        $this->inTenant($tenant, function () use ($reassessmentAId): void {
            $this->assertSame(FollowUpStatus::PENDING, FollowUp::findOrFail($reassessmentAId)->follow_up_status_id);
        });
    }
}
