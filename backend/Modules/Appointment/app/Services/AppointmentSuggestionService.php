<?php

declare(strict_types=1);

namespace Modules\Appointment\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Appointment\Models\AppointmentType;
use Modules\Appointment\Models\WellChildInterval;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use QCH\Core\Support\TenantTime;

/**
 * Next-appointment proposal (odd/tasks/edi-agenda.md S7). It reads the
 * follow-ups the server already derived from the screening color, so the
 * suggestion can never disagree with the clinical conduct:
 *   amarillo -> open reassessment: its due date, as a screening appointment;
 *   rojo     -> open referral: no routine re-screen, point to the referral;
 *   verde / no screening -> well-child interval by age (data table).
 * It only proposes; staff confirms with one click.
 */
final class AppointmentSuggestionService
{
    private const OPEN_STATUSES = [FollowUpStatus::PENDING, FollowUpStatus::OVERDUE, FollowUpStatus::IN_PROGRESS];

    /**
     * @return array{suggested_date: ?string, appointment_type_id: string, reason_key: string, reason: string, follow_up_id: ?string}
     */
    public function suggestFor(Child $child): array
    {
        $openFollowUps = FollowUp::query()
            ->where('child_id', $child->id)
            ->whereIn('follow_up_status_id', self::OPEN_STATUSES)
            ->orderBy('due_date')
            ->get();

        $reassessment = $openFollowUps->firstWhere('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS);
        if ($reassessment !== null) {
            return $this->suggestion(
                $this->notBeforeTomorrow($this->civil($reassessment->due_date)),
                AppointmentType::SCREENING,
                'reassessment',
                'Resultado amarillo: el EDI se vuelve a aplicar dentro de los 3 meses siguientes, junto con estimulación temprana.',
                $reassessment->id,
            );
        }

        $referral = $openFollowUps->firstWhere('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL);
        if ($referral !== null) {
            return $this->suggestion(
                null,
                AppointmentType::CHECK_UP,
                'referral',
                'Resultado rojo: el niño tiene una referencia a especialista pendiente; no se agenda una revaloración de rutina.',
                $referral->id,
            );
        }

        return $this->wellChildSuggestion($child);
    }

    /**
     * @return array{suggested_date: ?string, appointment_type_id: string, reason_key: string, reason: string, follow_up_id: ?string}
     */
    private function wellChildSuggestion(Child $child): array
    {
        $today = $this->civil(TenantTime::today());
        $lastVisitDate = Visit::query()->where('child_id', $child->id)->max('visit_date');
        $base = $lastVisitDate !== null ? $this->civil($lastVisitDate) : $today;

        $birthDate = $this->civil($child->birth_date);
        $ageDays = (int) $birthDate->diffInDays($base);

        $interval = WellChildInterval::query()
            ->where('min_age_days', '<=', $ageDays)
            ->where(fn ($query) => $query->whereNull('max_age_days')->orWhere('max_age_days', '>=', $ageDays))
            ->orderBy('min_age_days')
            ->first();

        $intervalDays = $interval?->interval_days ?? 180;

        return $this->suggestion(
            $this->notBeforeTomorrow($base->addDays($intervalDays)),
            AppointmentType::SCREENING,
            'well_child',
            'Control del niño sano: '.($interval?->source ?? 'cada 6 meses').'.',
            null,
        );
    }

    /**
     * Civil dates (birth_date, visit_date, due_date) are calendar days; they are
     * compared at UTC midnight and never shifted by a zone.
     */
    private function civil(mixed $value): CarbonImmutable
    {
        $date = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }

    private function notBeforeTomorrow(CarbonImmutable $date): CarbonImmutable
    {
        $tomorrow = $this->civil(TenantTime::today())->addDay();

        return $date->lessThan($tomorrow) ? $tomorrow : $date;
    }

    /**
     * @return array{suggested_date: ?string, appointment_type_id: string, reason_key: string, reason: string, follow_up_id: ?string}
     */
    private function suggestion(?CarbonImmutable $date, string $typeId, string $reasonKey, string $reason, ?string $followUpId): array
    {
        return [
            'suggested_date' => $date?->format('Y-m-d'),
            'appointment_type_id' => $typeId,
            'reason_key' => $reasonKey,
            'reason' => $reason,
            'follow_up_id' => $followUpId,
        ];
    }
}
