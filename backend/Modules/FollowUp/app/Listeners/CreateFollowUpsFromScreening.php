<?php

declare(strict_types=1);

namespace Modules\FollowUp\Listeners;

use Illuminate\Support\Carbon;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Services\FollowUpCreationService;
use Modules\Screening\Events\ScreeningCompleted;
use Modules\Screening\Models\Screening;

/**
 * design D1/D5 -- runs SYNCHRONOUSLY inside ScreeningLifecycleService::complete()'s
 * transaction (no ShouldQueue). Close-then-create ordering (REQ-FUP-004):
 * closes every open reassess_3_months follow-up of the child whose
 * originating screening's visit predates (or ties) this one, THEN delegates
 * to FollowUpCreationService for the completing screening's own conducts. A
 * failure here rolls back the whole completion -- the product's core
 * promise: a completed screening never exists without its conduct.
 */
final class CreateFollowUpsFromScreening
{
    public function __construct(
        private readonly FollowUpCreationService $creationService,
    ) {}

    public function handle(ScreeningCompleted $event): void
    {
        $screening = $event->screening;
        $completingVisitDate = $this->visitDate($screening);

        $this->closeOpenReassessments($screening, $completingVisitDate);
        $this->creationService->createForScreening($screening);
    }

    /**
     * REQ-FUP-004 -- only reassess_3_months closes; specialist_referral and
     * confirmatory_test never auto-close (they close manually). The
     * visit-date guard keeps a late-captured OLDER test from "answering" a
     * follow-up it predates (a backfilled screening does not close a newer
     * reassessment window).
     */
    private function closeOpenReassessments(Screening $screening, Carbon $completingVisitDate): void
    {
        $openStatuses = [FollowUpStatus::PENDING, FollowUpStatus::IN_PROGRESS, FollowUpStatus::OVERDUE];

        $candidates = FollowUp::query()
            ->where('child_id', $screening->child_id)
            ->where('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS)
            ->whereIn('follow_up_status_id', $openStatuses)
            ->where('screening_id', '!=', $screening->id)
            ->with('screening.visit')
            ->get();

        foreach ($candidates as $followUp) {
            $originScreening = $followUp->screening;
            if ($originScreening === null) {
                continue;
            }

            $originVisitDate = $this->visitDate($originScreening);

            if ($originVisitDate->lte($completingVisitDate)) {
                $followUp->forceFill([
                    'follow_up_status_id' => FollowUpStatus::COMPLETED,
                    'closed_by_screening_id' => $screening->id,
                    'completed_at' => now(),
                ])->save();
            }
        }
    }

    private function visitDate(Screening $screening): Carbon
    {
        $visitDate = $screening->visit?->visit_date;

        return $visitDate instanceof Carbon ? $visitDate : Carbon::parse($visitDate);
    }
}
