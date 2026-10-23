<?php

declare(strict_types=1);

namespace Modules\Stimulation\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Stimulation\Models\StimulationAttendance;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupMember;

/**
 * REQ-SA-001/002, design §5 — bulk idempotent attendance upsert keyed by
 * (stimulation_group_id, child_id, session_date).
 */
class StimulationAttendanceService
{
    /**
     * @param  array{session_date: string, entries: array<int, array<string, mixed>>}  $validated
     * @return Collection<int, StimulationAttendance>
     */
    public function upsert(StimulationGroup $group, array $validated): Collection
    {
        $sessionDate = $validated['session_date'];
        $entries = $validated['entries'];

        $this->guardActiveGroup($group);
        $this->guardDateBounds($group, $sessionDate);
        $members = $this->guardActiveMembership($group, $entries);

        return DB::transaction(function () use ($group, $sessionDate, $entries, $members): Collection {
            return collect($entries)->map(
                fn (array $entry) => $this->upsertEntry($group, $sessionDate, $entry, $members)
            );
        });
    }

    private function guardActiveGroup(StimulationGroup $group): void
    {
        if (! $group->is_active) {
            throw ValidationException::withMessages([
                'stimulation_group_id' => ['El grupo está inactivo y no acepta registro de asistencia.'],
            ]);
        }
    }

    private function guardDateBounds(StimulationGroup $group, string $sessionDate): void
    {
        $start = $group->getRawOriginal('start_date');

        if (strtotime($sessionDate) < strtotime((string) $start)) {
            throw ValidationException::withMessages([
                'session_date' => ['La fecha de la sesión no puede ser anterior al inicio del grupo.'],
            ]);
        }
    }

    /**
     * REQ-SA-002 — every entry's child MUST be an active member of the
     * group; otherwise the whole batch fails with 422 naming the offending
     * child, and NOTHING is persisted (checked before the transaction opens).
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return Collection<string, StimulationGroupMember> indexed by child_id
     */
    private function guardActiveMembership(StimulationGroup $group, array $entries): Collection
    {
        $childIds = collect($entries)->pluck('child_id')->all();

        $members = StimulationGroupMember::where('stimulation_group_id', $group->id)
            ->whereIn('child_id', $childIds)
            ->get()
            ->keyBy('child_id');

        foreach ($childIds as $childId) {
            if (! $members->has($childId)) {
                throw ValidationException::withMessages([
                    'entries' => ["El niño {$childId} no es un miembro activo del grupo."],
                ]);
            }
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Collection<string, StimulationGroupMember>  $members
     */
    private function upsertEntry(StimulationGroup $group, string $sessionDate, array $entry, Collection $members): StimulationAttendance
    {
        $member = $members->get($entry['child_id']);

        $attributes = [
            'attended' => (bool) ($entry['attended'] ?? false),
            'homework_done' => (bool) ($entry['homework_done'] ?? false),
            'attendance_payment_status_id' => $entry['attendance_payment_status_id']
                ?? $member->default_attendance_payment_status_id,
        ];

        $existing = $this->findExisting($group->id, $entry['child_id'], $sessionDate);

        if ($existing) {
            return $this->applyUpdate($existing, $attributes);
        }

        try {
            // Nested DB::transaction() inside the caller's open transaction
            // becomes a Postgres SAVEPOINT (VisitService precedent): a unique
            // violation here rolls back only THIS entry, not the whole batch.
            return DB::transaction(fn (): StimulationAttendance => StimulationAttendance::create([
                'stimulation_group_id' => $group->id,
                'child_id' => $entry['child_id'],
                'session_date' => $sessionDate,
                ...$attributes,
            ]));
        } catch (UniqueConstraintViolationException) {
            // D9 — a concurrent request won the race; re-read under the SAME
            // outer transaction and convert this attempt into an update.
            $winner = $this->findExisting($group->id, $entry['child_id'], $sessionDate);

            return $this->applyUpdate($winner, $attributes);
        }
    }

    private function findExisting(string $groupId, string $childId, string $sessionDate): ?StimulationAttendance
    {
        return StimulationAttendance::withTrashed()
            ->where('stimulation_group_id', $groupId)
            ->where('child_id', $childId)
            ->where('session_date', $sessionDate)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyUpdate(?StimulationAttendance $row, array $attributes): StimulationAttendance
    {
        if (! $row) {
            throw ValidationException::withMessages([
                'entries' => ['No se pudo registrar la asistencia; vuelve a intentarlo.'],
            ]);
        }

        if ($row->trashed()) {
            $row->restore();
        }

        $row->fill($attributes);
        $row->save();

        return $row->refresh();
    }
}
