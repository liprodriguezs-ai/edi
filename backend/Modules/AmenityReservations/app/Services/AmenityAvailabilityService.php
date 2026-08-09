<?php

namespace Modules\AmenityReservations\Services;

use Carbon\Carbon;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;

/**
 * Computes amenity availability for a date (or a small date range), combining
 * STRUCTURAL rules (out_of_service, available_weekdays, amenity_blackout)
 * with OCCUPANCY (existing amenity_reservation rows that still `holds_slot`).
 *
 * All "today"/date math happens in the TENANT'S timezone (the app process
 * runs in UTC) — never bare Carbon::today()/now(). This matters because a
 * late-UTC instant can already be "tomorrow" in UTC while it is still "today"
 * for a tenant west of UTC (e.g. America/Mexico_City, UTC-6): using bare UTC
 * would incorrectly reject a same-day request as a "past date".
 */
class AmenityAvailabilityService
{
    /** Inclusive day-count cap for a single availability request (defensive). */
    public const MAX_RANGE_DAYS = 31;

    /**
     * @return array{amenity_id: string, mode: string, timezone: string, days: array<int, array<string, mixed>>}
     */
    public function forRange(Amenity $amenity, string $startDate, ?string $endDate = null): array
    {
        $timezone = $this->tenantTimezone();
        $today = now($timezone)->toDateString();

        $start = Carbon::parse($startDate, $timezone)->startOfDay();
        $end = ($endDate !== null ? Carbon::parse($endDate, $timezone) : $start->copy())->startOfDay();

        if ($start->toDateString() < $today) {
            throw new \InvalidArgumentException('No se puede consultar disponibilidad de fechas pasadas.');
        }

        if ($end->lessThan($start)) {
            throw new \InvalidArgumentException('La fecha final debe ser posterior o igual a la fecha inicial.');
        }

        $totalDays = (int) $start->diffInDays($end) + 1;

        if ($totalDays > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('El rango de fechas no puede exceder '.self::MAX_RANGE_DAYS.' días.');
        }

        $amenity->loadMissing(['slots', 'blackouts']);

        $isBySlot = $amenity->reservation_mode_id === ReservationMode::BY_SLOT;

        // Batch occupancy for the WHOLE range into a single query (instead of
        // one query per open day) — the schedule-x calendar requests ranges
        // up to MAX_RANGE_DAYS, so a per-day query would fire up to 31 times.
        $holdingByDayDates = $isBySlot ? [] : $this->batchHoldingByDayDates($amenity, $start, $end);
        $occupancyByDateAndSlot = $isBySlot ? $this->batchOccupancyBySlot($amenity, $start, $end) : [];

        $days = [];
        for ($offset = 0; $offset < $totalDays; $offset++) {
            $date = $start->copy()->addDays($offset);
            $days[] = $isBySlot
                ? $this->buildBySlotDay($amenity, $date, $timezone, $occupancyByDateAndSlot[$date->toDateString()] ?? [])
                : $this->buildByDayDay($amenity, $date, $holdingByDayDates);
        }

        return [
            'amenity_id' => $amenity->id,
            'mode' => $isBySlot ? 'by_slot' : 'by_day',
            'timezone' => $timezone,
            'days' => $days,
        ];
    }

    /**
     * @return array{amenity_id: string, mode: string, timezone: string, days: array<int, array<string, mixed>>}
     */
    public function forDate(Amenity $amenity, string $date): array
    {
        return $this->forRange($amenity, $date, $date);
    }

    /**
     * @param  array<string, bool>  $holdingByDayDates  date string => true, from batchHoldingByDayDates()
     * @return array<string, mixed>
     */
    private function buildByDayDay(Amenity $amenity, Carbon $date, array $holdingByDayDates): array
    {
        [$open, $reason] = $this->structuralAvailability($amenity, $date);

        $available = $open && ! ($holdingByDayDates[$date->toDateString()] ?? false);

        return [
            'date' => $date->toDateString(),
            'open' => $open,
            'reason' => $open ? null : $reason,
            'available' => $available,
        ];
    }

    /**
     * @param  array<string, int>  $occupancy  slot_id => occupied count, for THIS date, from batchOccupancyBySlot()
     * @return array<string, mixed>
     */
    private function buildBySlotDay(Amenity $amenity, Carbon $date, string $timezone, array $occupancy): array
    {
        [$open, $reason] = $this->structuralAvailability($amenity, $date);

        $weekday = (int) $date->dayOfWeek;
        $slotsForWeekday = $amenity->slots->where('weekday', $weekday)->values();

        if (! $open) {
            $occupancy = [];
        }

        $slots = $slotsForWeekday->map(function ($slot) use ($amenity, $occupancy, $open, $date, $timezone): array {
            $capacity = $slot->capacity_override ?? $amenity->capacity;
            $taken = $open ? ($occupancy[$slot->id] ?? 0) : 0;
            $free = $open ? max(0, $capacity - $taken) : 0;

            return [
                'slot_id' => $slot->id,
                'weekday' => $slot->weekday,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'start_at' => Carbon::parse($date->toDateString().' '.$slot->start_time, $timezone)->utc()->toIso8601String(),
                'end_at' => Carbon::parse($date->toDateString().' '.$slot->end_time, $timezone)->utc()->toIso8601String(),
                'capacity' => $capacity,
                'taken' => $taken,
                'free' => $free,
            ];
        })->values()->all();

        $available = $open && collect($slots)->contains(static fn (array $slot): bool => $slot['free'] > 0);

        return [
            'date' => $date->toDateString(),
            'open' => $open,
            'reason' => $open ? null : $reason,
            'available' => $available,
            'slots' => $slots,
        ];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function structuralAvailability(Amenity $amenity, Carbon $date): array
    {
        if ($amenity->out_of_service) {
            return [false, 'out_of_service'];
        }

        $weekday = (int) $date->dayOfWeek;

        if (! in_array($weekday, $amenity->available_weekdays ?? [], true)) {
            return [false, 'closed_weekday'];
        }

        $dateString = $date->toDateString();

        $isBlackedOut = $amenity->blackouts->contains(
            static fn ($blackout): bool => $dateString >= $blackout->start_date->toDateString()
                && $dateString <= $blackout->end_date->toDateString()
        );

        if ($isBlackedOut) {
            return [false, 'blackout'];
        }

        return [true, null];
    }

    /**
     * ONE query for the whole [start, end] range instead of one query per
     * open day. Returns which dates in the range have an active (holds_slot,
     * by_day) holding reservation.
     *
     * @return array<string, bool> date string => true
     */
    private function batchHoldingByDayDates(Amenity $amenity, Carbon $start, Carbon $end): array
    {
        return AmenityReservation::query()
            ->where('amenity_id', $amenity->id)
            ->whereBetween('reservation_date', [$start->toDateString(), $end->toDateString()])
            ->where('holds_slot', true)
            ->whereNull('slot_id')
            ->distinct()
            ->pluck('reservation_date')
            ->mapWithKeys(static fn ($date): array => [
                ($date instanceof Carbon ? $date->toDateString() : (string) $date) => true,
            ])
            ->all();
    }

    /**
     * ONE query for the whole [start, end] range instead of one query per
     * open day. Returns occupied slot counts grouped by date, then by slot.
     *
     * @return array<string, array<string, int>> date string => (slot_id => occupied count)
     */
    private function batchOccupancyBySlot(Amenity $amenity, Carbon $start, Carbon $end): array
    {
        $rows = AmenityReservation::query()
            ->where('amenity_id', $amenity->id)
            ->whereBetween('reservation_date', [$start->toDateString(), $end->toDateString()])
            ->where('holds_slot', true)
            ->whereNotNull('slot_id')
            ->selectRaw('reservation_date, slot_id, COUNT(*) as occupied')
            ->groupBy('reservation_date', 'slot_id')
            ->get();

        $occupancy = [];

        foreach ($rows as $row) {
            $dateKey = $row->reservation_date instanceof Carbon
                ? $row->reservation_date->toDateString()
                : (string) $row->reservation_date;

            $occupancy[$dateKey][$row->slot_id] = (int) $row->occupied;
        }

        return $occupancy;
    }

    private function tenantTimezone(): string
    {
        return tenant('timezone') ?: config('app.timezone');
    }
}
