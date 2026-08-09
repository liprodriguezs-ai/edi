<?php

namespace Modules\AmenityReservations\Services;

use Carbon\Carbon;
use Modules\AmenityReservations\Models\AmenityReservation;

/**
 * Cluster-wide, ANONYMIZED occupancy for the oversight-committee transparency
 * calendar (spec: "who booked what" must never be derivable from this data).
 *
 * Deliberately independent from AmenityAvailabilityService: that service
 * answers "can I book THIS amenity on THIS date" for one amenity at a time
 * (structural rules + occupancy) and is consumed by residents deciding
 * whether to reserve. This service answers "what is occupied across EVERY
 * amenity in the cluster for this range" and is consumed by a read-only
 * transparency view — it never touches structural availability (blackouts/
 * weekdays/out_of_service) and, critically, NEVER selects unit_id, user_id,
 * or the reservation's own id. Only amenity identity + date/slot + an
 * occupied/capacity COUNT cross this boundary.
 */
class AmenityOccupancyService
{
    /** Inclusive day-count cap for a single occupancy request (defensive, mirrors AmenityAvailabilityService). */
    public const MAX_RANGE_DAYS = 62;

    /**
     * @return array<int, array{amenity_id: string, amenity_name: string, reservation_date: string, slot_id: ?string, start_at: ?string, end_at: ?string, occupied: int, capacity: int}>
     */
    public function forRange(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            throw new \InvalidArgumentException('La fecha final debe ser posterior o igual a la fecha inicial.');
        }

        $totalDays = (int) $start->diffInDays($end) + 1;

        if ($totalDays > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('El rango de fechas no puede exceder '.self::MAX_RANGE_DAYS.' días.');
        }

        $entries = array_merge(
            $this->byDayEntries($start, $end),
            $this->bySlotEntries($start, $end),
        );

        usort(
            $entries,
            static fn (array $a, array $b): int => [$a['reservation_date'], $a['amenity_name'], $a['slot_id']]
                <=> [$b['reservation_date'], $b['amenity_name'], $b['slot_id']]
        );

        return $entries;
    }

    /**
     * by_day amenities: the amenity_reservation_by_day_unique partial index
     * guarantees at most ONE holding reservation per (tenant, amenity, date),
     * so occupied is always 1 when a row exists — capacity is fixed at 1 (the
     * whole day is either occupied or it isn't, there is no "n of m" here).
     *
     * @return array<int, array<string, mixed>>
     */
    private function byDayEntries(Carbon $start, Carbon $end): array
    {
        $rows = AmenityReservation::query()
            ->join('amenity', 'amenity.id', '=', 'amenity_reservation.amenity_id')
            ->where('amenity_reservation.holds_slot', true)
            ->whereNull('amenity_reservation.slot_id')
            ->whereBetween('amenity_reservation.reservation_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('amenity_reservation.amenity_id', 'amenity.name', 'amenity_reservation.reservation_date')
            ->selectRaw(
                'amenity_reservation.amenity_id as amenity_id, '
                .'amenity.name as amenity_name, '
                .'amenity_reservation.reservation_date as reservation_date, '
                .'COUNT(*) as occupied'
            )
            ->get();

        return $rows->map(fn ($row): array => [
            'amenity_id' => $row->amenity_id,
            'amenity_name' => $row->amenity_name,
            'reservation_date' => $this->dateString($row->reservation_date),
            'slot_id' => null,
            'start_at' => null,
            'end_at' => null,
            'occupied' => (int) $row->occupied,
            'capacity' => 1,
        ])->all();
    }

    /**
     * by_slot amenities: grouped by (amenity, date, slot) — occupied/capacity
     * is a real "n of m" count against the slot's (or amenity's default)
     * capacity. start_at/end_at are the reservation's own MATERIALIZED UTC
     * instants (identical across every row in the group, since they are
     * derived deterministically from date + slot start/end time — see
     * ReservationService::materializeWindow()) so no extra timezone math is
     * needed here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bySlotEntries(Carbon $start, Carbon $end): array
    {
        $rows = AmenityReservation::query()
            ->join('amenity', 'amenity.id', '=', 'amenity_reservation.amenity_id')
            ->join('amenity_slot', 'amenity_slot.id', '=', 'amenity_reservation.slot_id')
            ->where('amenity_reservation.holds_slot', true)
            ->whereNotNull('amenity_reservation.slot_id')
            ->whereBetween('amenity_reservation.reservation_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy(
                'amenity_reservation.amenity_id',
                'amenity.name',
                'amenity_reservation.reservation_date',
                'amenity_reservation.slot_id',
                'amenity_slot.capacity_override',
                'amenity.capacity',
            )
            ->selectRaw(
                'amenity_reservation.amenity_id as amenity_id, '
                .'amenity.name as amenity_name, '
                .'amenity_reservation.reservation_date as reservation_date, '
                .'amenity_reservation.slot_id as slot_id, '
                .'MIN(amenity_reservation.start_at) as start_at, '
                .'MIN(amenity_reservation.end_at) as end_at, '
                .'COUNT(*) as occupied, '
                .'COALESCE(amenity_slot.capacity_override, amenity.capacity) as capacity'
            )
            ->get();

        return $rows->map(fn ($row): array => [
            'amenity_id' => $row->amenity_id,
            'amenity_name' => $row->amenity_name,
            'reservation_date' => $this->dateString($row->reservation_date),
            'slot_id' => $row->slot_id,
            'start_at' => $row->start_at ? Carbon::parse($row->start_at)->utc()->toIso8601String() : null,
            'end_at' => $row->end_at ? Carbon::parse($row->end_at)->utc()->toIso8601String() : null,
            'occupied' => (int) $row->occupied,
            'capacity' => (int) $row->capacity,
        ])->all();
    }

    private function dateString(mixed $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : (string) $date;
    }
}
