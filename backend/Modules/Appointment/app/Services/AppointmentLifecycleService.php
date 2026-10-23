<?php

declare(strict_types=1);

namespace Modules\Appointment\Services;

use App\Notifications\Concerns\DispatchesNotifications;
use App\Notifications\NotificationCategory;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Appointment\Exceptions\AppointmentDomainException;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\ChildRecord\Services\VisitService;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\FollowUp\Models\FollowUp;
use QCH\Core\Support\TenantTime;

/**
 * Appointment lifecycle (odd/tasks/edi-agenda.md S2-S8). Every transition locks
 * the row inside a transaction and re-checks the status server-side -- same
 * guarded-writes stack as FollowUpLifecycleService. Double booking is rejected
 * by the appointment_practitioner_no_overlap exclusion constraint and surfaced
 * here as a validation error.
 */
final class AppointmentLifecycleService
{
    use DispatchesNotifications;

    /** PostgreSQL SQLSTATE for an exclusion-constraint violation. */
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(private readonly VisitService $visitService) {}

    /**
     * S3 -- books a future appointment. Duration defaults to the type's.
     *
     * @param  array<string, mixed>  $data  validated AppointmentCreateRequest payload
     */
    public function book(array $data): Appointment
    {
        $this->assertFollowUpBelongsToChild($data['follow_up_id'] ?? null, $data['child_id']);

        $start = Carbon::parse($data['starts_at']);

        return $this->guardOverlap(fn (): Appointment => $this->persistNew($data, [
            'appointment_status_id' => AppointmentStatus::SCHEDULED,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes($this->durationFor($data)),
            'is_walk_in' => false,
        ]));
    }

    /**
     * S5 -- "Registrar sin cita": born arrived, starting now. Walk-ins are
     * excluded from the overlap constraint, so they never block the agenda.
     *
     * @param  array<string, mixed>  $data  validated AppointmentWalkInRequest payload
     */
    public function walkIn(array $data): Appointment
    {
        $now = now();

        return $this->persistNew($data, [
            'appointment_status_id' => AppointmentStatus::ARRIVED,
            'starts_at' => $now,
            'ends_at' => $now->copy()->addMinutes($this->durationFor($data)),
            'arrived_at' => $now,
            'is_walk_in' => true,
        ]);
    }

    /**
     * S2 -- scheduled -> confirmed.
     */
    public function confirm(Appointment $appointment): Appointment
    {
        return $this->transition($appointment, function (Appointment $locked): void {
            if ($locked->appointment_status_id !== AppointmentStatus::SCHEDULED) {
                throw AppointmentDomainException::invalidStatusTransition('Solo una cita agendada puede confirmarse.');
            }

            $locked->forceFill([
                'appointment_status_id' => AppointmentStatus::CONFIRMED,
                'confirmed_at' => now(),
            ])->save();
        });
    }

    /**
     * S5 -- scheduled|confirmed -> arrived ("Llegó"), only on the appointment day.
     */
    public function arrive(Appointment $appointment): Appointment
    {
        return $this->transition($appointment, function (Appointment $locked): void {
            $this->assertBookedForToday($locked, 'Solo se puede marcar la llegada el día de la cita.');

            $locked->forceFill([
                'appointment_status_id' => AppointmentStatus::ARRIVED,
                'arrived_at' => now(),
            ])->save();

            $this->notifyArrival($locked);
        });
    }

    /**
     * S5 -- tells the practitioner their patient is in the waiting room.
     * notifyEvent() defers via DB::afterCommit, so calling it inside the open
     * transaction is safe (same as ReferralService).
     */
    private function notifyArrival(Appointment $appointment): void
    {
        $appointment->loadMissing(['child', 'practitioner']);
        $userId = $appointment->practitioner?->user_id;

        if ($userId === null) {
            return;
        }

        $childName = trim((string) $appointment->child?->first_name.' '.(string) $appointment->child?->last_name_paternal);

        $this->notifyEvent(
            'appointment.arrived',
            [$userId],
            ['child' => $childName],
            'Paciente en espera',
            '{child} llegó y está en la sala de espera.',
            ['category' => NotificationCategory::APPOINTMENT, 'click_action' => '/agenda'],
        );
    }

    /**
     * S6 -- "Iniciar atención": creates the visit through VisitService (folio
     * generated there) and closes the appointment as fulfilled. A center
     * without front desk can start straight from scheduled/confirmed on the
     * appointment day; the arrival is stamped at the same moment.
     */
    public function start(Appointment $appointment): Appointment
    {
        return $this->transition($appointment, function (Appointment $locked): void {
            if ($locked->appointment_status_id !== AppointmentStatus::ARRIVED) {
                $this->assertBookedForToday($locked, 'Solo se puede iniciar la atención el día de la cita.');
            }

            $now = now();
            $visit = $this->visitService->create([
                'child_id' => $locked->child_id,
                'health_center_id' => $locked->health_center_id,
                'practitioner_id' => $locked->practitioner_id,
                'visit_date' => TenantTime::today(),
            ]);

            $locked->forceFill([
                'appointment_status_id' => AppointmentStatus::FULFILLED,
                'arrived_at' => $locked->arrived_at ?? $now,
                'started_at' => $now,
                'visit_id' => $visit->id,
            ])->save();
        });
    }

    /**
     * S2 -- moves a scheduled|confirmed appointment; confirmation resets
     * because the caregiver agreed to the old time, not the new one.
     *
     * @param  array<string, mixed>  $data  validated AppointmentRescheduleRequest payload
     */
    public function reschedule(Appointment $appointment, array $data): Appointment
    {
        return $this->guardOverlap(fn (): Appointment => $this->transition($appointment, function (Appointment $locked) use ($data): void {
            if (! in_array($locked->appointment_status_id, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true)) {
                throw AppointmentDomainException::invalidStatusTransition('Solo una cita agendada o confirmada puede reprogramarse.');
            }

            $start = Carbon::parse($data['starts_at']);
            $minutes = isset($data['duration_minutes'])
                ? (int) $data['duration_minutes']
                : (int) $locked->starts_at->diffInMinutes($locked->ends_at);

            $attributes = [
                'appointment_status_id' => AppointmentStatus::SCHEDULED,
                'confirmed_at' => null,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($minutes),
            ];

            // The appointment happens where the practitioner works: a new
            // practitioner moves the health center with them.
            if (isset($data['practitioner_id']) && $data['practitioner_id'] !== $locked->practitioner_id) {
                $attributes['practitioner_id'] = $data['practitioner_id'];
                $attributes['health_center_id'] = PractitionerProfile::query()
                    ->whereKey($data['practitioner_id'])
                    ->value('health_center_id') ?? $locked->health_center_id;
            }

            $locked->forceFill($attributes)->save();
        }));
    }

    /**
     * S2 -- scheduled|confirmed|arrived -> cancelled, mandatory reason.
     */
    public function cancel(Appointment $appointment, string $reason): Appointment
    {
        return $this->transition($appointment, function (Appointment $locked) use ($reason): void {
            $locked->forceFill([
                'appointment_status_id' => AppointmentStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
        });
    }

    /**
     * S8 -- scheduled|confirmed -> no_show, only once the start time passed.
     */
    public function markNoShow(Appointment $appointment): Appointment
    {
        return $this->transition($appointment, function (Appointment $locked): void {
            if (! in_array($locked->appointment_status_id, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true)
                || $locked->starts_at->isFuture()) {
                throw AppointmentDomainException::invalidStatusTransition(
                    'Solo una cita agendada o confirmada cuya hora ya pasó puede marcarse como no asistió.'
                );
            }

            $locked->forceFill([
                'appointment_status_id' => AppointmentStatus::NO_SHOW,
                'no_show_at' => now(),
            ])->save();
        });
    }

    /**
     * S8 -- end-of-day close: every scheduled|confirmed appointment of the given
     * clinic day whose start already passed becomes no_show. Arrived ones are
     * left alone (the child is still in the waiting room).
     *
     * @return int number of appointments closed
     */
    public function markDayNoShow(string $date, ?string $healthCenterId = null): int
    {
        [$dayStart, $dayEnd] = TenantTime::dayBounds($date);
        $cutoff = now()->lessThan($dayEnd) ? now() : $dayEnd;

        return DB::transaction(function () use ($dayStart, $cutoff, $healthCenterId): int {
            $appointments = Appointment::query()
                ->whereIn('appointment_status_id', [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED])
                ->where('starts_at', '>=', $dayStart->utc())
                ->where('starts_at', '<', $cutoff->copy()->utc())
                ->when($healthCenterId, fn ($query) => $query->where('health_center_id', $healthCenterId))
                ->lockForUpdate()
                ->get();

            foreach ($appointments as $appointment) {
                $appointment->forceFill([
                    'appointment_status_id' => AppointmentStatus::NO_SHOW,
                    'no_show_at' => now(),
                ])->save();
            }

            return $appointments->count();
        });
    }

    /**
     * Notes are the only in-place edit, and only while the appointment is open.
     *
     * @param  array<string, mixed>  $data  validated AppointmentUpdateRequest payload
     */
    public function updateNotes(Appointment $appointment, array $data): Appointment
    {
        return $this->transition($appointment, function (Appointment $locked) use ($data): void {
            if (array_key_exists('notes', $data)) {
                $locked->forceFill(['notes' => $data['notes']])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $lifecycle
     */
    private function persistNew(array $data, array $lifecycle): Appointment
    {
        $healthCenterId = $this->healthCenterFor($data);

        return DB::transaction(function () use ($data, $lifecycle, $healthCenterId): Appointment {
            $appointment = new Appointment;
            $appointment->fill([
                'child_id' => $data['child_id'],
                'practitioner_id' => $data['practitioner_id'],
                'health_center_id' => $healthCenterId,
                'appointment_type_id' => $data['appointment_type_id'],
                'follow_up_id' => $data['follow_up_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $appointment->forceFill($lifecycle)->save();

            return $appointment->fresh();
        });
    }

    /**
     * The appointment happens where the practitioner works. A client-sent center
     * is accepted only when it IS the practitioner's center.
     *
     * @param  array<string, mixed>  $data
     */
    private function healthCenterFor(array $data): ?string
    {
        $practitionerCenter = PractitionerProfile::query()->whereKey($data['practitioner_id'])->value('health_center_id');
        $requested = $data['health_center_id'] ?? null;

        if ($requested !== null && $practitionerCenter !== null && $requested !== $practitionerCenter) {
            throw ValidationException::withMessages([
                'health_center_id' => 'El profesional no atiende en ese centro de salud.',
            ]);
        }

        return $requested ?? $practitionerCenter;
    }

    /**
     * Locks the row, freezes terminal states, runs the mutation, returns fresh.
     *
     * @param  Closure(Appointment): void  $mutation
     */
    private function transition(Appointment $appointment, Closure $mutation): Appointment
    {
        return DB::transaction(function () use ($appointment, $mutation): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->appointment_status_id, AppointmentStatus::TERMINAL, true)) {
                throw AppointmentDomainException::frozen();
            }

            $mutation($locked);

            return $locked->fresh();
        });
    }

    /**
     * Runs a write and turns the exclusion-constraint violation into the
     * Spanish validation error the booking form shows under the time field.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function guardOverlap(Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
                throw ValidationException::withMessages([
                    'starts_at' => 'El profesional ya tiene una cita en ese horario.',
                ]);
            }

            throw $e;
        }
    }

    private function assertBookedForToday(Appointment $appointment, string $message): void
    {
        if (! in_array($appointment->appointment_status_id, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true)) {
            throw AppointmentDomainException::invalidStatusTransition($message);
        }

        if (TenantTime::inTenantZone($appointment->starts_at)->format('Y-m-d') !== TenantTime::today()) {
            throw AppointmentDomainException::invalidStatusTransition($message);
        }
    }

    private function assertFollowUpBelongsToChild(?string $followUpId, string $childId): void
    {
        if ($followUpId !== null && FollowUp::query()->whereKey($followUpId)->value('child_id') !== $childId) {
            throw AppointmentDomainException::followUpMismatch();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function durationFor(array $data): int
    {
        if (isset($data['duration_minutes'])) {
            return (int) $data['duration_minutes'];
        }

        return (int) AppointmentType::query()->whereKey($data['appointment_type_id'])->value('default_duration_minutes');
    }
}
