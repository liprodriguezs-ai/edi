<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Support\Facades\DB;
use Modules\FollowUp\Exceptions\FollowUpDomainException;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;

/**
 * start/complete/cancel (design D10, REQ-FUP-006). Every mutation locks the
 * follow_up row inside a transaction and re-checks status server-side --
 * same guarded-writes stack used by Screening.
 */
final class FollowUpLifecycleService
{
    private const TERMINAL_STATUSES = [FollowUpStatus::COMPLETED, FollowUpStatus::CANCELLED];

    /**
     * REQ-FUP-006 -- pending|overdue -> in_progress.
     */
    public function start(FollowUp $followUp): FollowUp
    {
        return DB::transaction(function () use ($followUp): FollowUp {
            $locked = $this->lock($followUp);
            // Terminal freeze (409 FOLLOW_UP_FROZEN) takes precedence over the
            // narrower "not pending/overdue" transition error -- completed and
            // cancelled must always answer FROZEN, never INVALID_STATUS_TRANSITION.
            $this->assertOpen($locked);

            if (! in_array($locked->follow_up_status_id, [FollowUpStatus::PENDING, FollowUpStatus::OVERDUE], true)) {
                throw FollowUpDomainException::invalidStatusTransition(
                    'Solo un seguimiento pendiente o vencido puede iniciarse.'
                );
            }

            $locked->forceFill(['follow_up_status_id' => FollowUpStatus::IN_PROGRESS])->save();

            return $locked->fresh();
        });
    }

    /**
     * REQ-FUP-006 -- open -> completed (manual). closed_by_screening_id
     * stays NULL, distinguishing a manual close from the auto-close step
     * (REQ-FUP-004/D5).
     */
    public function complete(FollowUp $followUp): FollowUp
    {
        return DB::transaction(function () use ($followUp): FollowUp {
            $locked = $this->lock($followUp);
            $this->assertOpen($locked);

            $locked->forceFill([
                'follow_up_status_id' => FollowUpStatus::COMPLETED,
                'completed_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * REQ-FUP-006 -- open -> cancelled, mandatory reason.
     *
     * @param  array{reason: string}  $data
     */
    public function cancel(FollowUp $followUp, array $data): FollowUp
    {
        return DB::transaction(function () use ($followUp, $data): FollowUp {
            $locked = $this->lock($followUp);
            $this->assertOpen($locked);

            $locked->forceFill([
                'follow_up_status_id' => FollowUpStatus::CANCELLED,
                'cancellation_reason' => $data['reason'],
            ])->save();

            return $locked->fresh();
        });
    }

    private function lock(FollowUp $followUp): FollowUp
    {
        /** @var FollowUp $locked */
        $locked = FollowUp::query()->whereKey($followUp->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function assertOpen(FollowUp $followUp): void
    {
        if (in_array($followUp->follow_up_status_id, self::TERMINAL_STATUSES, true)) {
            throw FollowUpDomainException::frozen();
        }
    }
}
