<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Support\Carbon;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Models\Screening;
use QCH\Core\Support\TenantTime;

/**
 * Color matrix + due dates + write-time status derivation (design D3/D4/D7,
 * REQ-FUP-002). Called by the listener AFTER the auto-close step (D5) --
 * this class only creates, it never closes.
 */
final class FollowUpCreationService
{
    /**
     * REQ-FUP-002 -- creates the follow-ups mandated by $screening's
     * authoritative global color. Idempotent: create-if-missing on
     * (screening_id, follow_up_type_id), backstopped by the DB partial
     * unique index (D2).
     */
    public function createForScreening(Screening $screening): void
    {
        foreach ($this->conductsFor($screening) as [$typeId, $dueDate]) {
            $this->createIfMissing($screening, $typeId, $dueDate);
        }
    }

    /**
     * @return array<int, array{0: string, 1: Carbon}>
     */
    private function conductsFor(Screening $screening): array
    {
        $visitDate = $screening->visit?->visit_date;
        $visitDate = $visitDate instanceof Carbon ? $visitDate : Carbon::parse($visitDate);

        $reassessDue = $visitDate->copy()->addMonths(3);
        $referralDue = $visitDate->copy()->addDays(30);

        return match ($screening->result_color_id) {
            ResultColor::RED => [
                [FollowUpType::SPECIALIST_REFERRAL, $referralDue],
                [FollowUpType::CONFIRMATORY_TEST, $referralDue],
            ],
            ResultColor::YELLOW => $this->yellowConducts($screening, $reassessDue, $referralDue),
            default => [], // green (or a null color, defensively) creates nothing
        };
    }

    /**
     * REQ-FUP-002 recurrence: current yellow AND the frozen
     * previous_result_color_id is also yellow (design D3) -- consecutive
     * yellow, deterministic across rescores and immune to later siblings.
     *
     * @return array<int, array{0: string, 1: Carbon}>
     */
    private function yellowConducts(Screening $screening, Carbon $reassessDue, Carbon $referralDue): array
    {
        $conducts = [[FollowUpType::REASSESS_3_MONTHS, $reassessDue]];

        if ($screening->previous_result_color_id === ResultColor::YELLOW) {
            $conducts[] = [FollowUpType::SPECIALIST_REFERRAL, $referralDue];
        }

        return $conducts;
    }

    private function createIfMissing(Screening $screening, string $followUpTypeId, Carbon $dueDate): void
    {
        $exists = FollowUp::where('screening_id', $screening->id)
            ->where('follow_up_type_id', $followUpTypeId)
            ->exists();

        if ($exists) {
            return;
        }

        // forceFill, NOT create(): child_id/screening_id/follow_up_type_id/
        // follow_up_status_id are deliberately excluded from $fillable
        // (REQ-FUP-006) so mass-assignment can never forge them.
        $followUp = new FollowUp;
        $followUp->forceFill([
            'child_id' => $screening->child_id,
            'screening_id' => $screening->id,
            'follow_up_type_id' => $followUpTypeId,
            'follow_up_status_id' => self::deriveStatusId($dueDate),
            'due_date' => $dueDate,
        ]);
        $followUp->save();
    }

    /**
     * REQ-FUP-005/D7 -- pure function of due_date vs the tenant's civil today, over the base
     * state {pending, overdue}. Compares ISO date strings (no mutation risk
     * on the Carbon instance the caller may still be using for `due_date`).
     */
    public static function deriveStatusId(Carbon|string $dueDate): string
    {
        $dueDateString = $dueDate instanceof Carbon ? $dueDate->toDateString() : Carbon::parse($dueDate)->toDateString();

        return $dueDateString < TenantTime::today()
            ? FollowUpStatus::OVERDUE
            : FollowUpStatus::PENDING;
    }
}
