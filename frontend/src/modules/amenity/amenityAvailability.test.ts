import {
  availabilityReasonLabel,
  buildAvailabilityDayMap,
  freeSlotsForDay,
  isDateSelectable,
} from '@/modules/amenity/amenityAvailability'
import type {
  IAmenityAvailabilityDay,
  IAmenityAvailabilityResponse,
} from '@/modules/amenity/schema'
import { describe, expect, it } from 'vitest'

const RESPONSE: IAmenityAvailabilityResponse = {
  amenity_id: 'amenity-1',
  mode: 'by_slot',
  timezone: 'America/Mexico_City',
  days: [
    { date: '2026-08-01', open: true, reason: null, available: true },
    { date: '2026-08-02', open: false, reason: 'closed_weekday', available: false },
    { date: '2026-08-03', open: false, reason: 'blackout', available: false },
    { date: '2026-08-04', open: true, reason: null, available: false },
  ],
}

describe('buildAvailabilityDayMap', () => {
  it('indexes every day by date', () => {
    const map = buildAvailabilityDayMap(RESPONSE)
    expect(map.size).toBe(4)
    expect(map.get('2026-08-01')?.available).toBe(true)
  })

  it('returns an empty map for undefined input', () => {
    expect(buildAvailabilityDayMap(undefined).size).toBe(0)
  })
})

describe('isDateSelectable', () => {
  const map = buildAvailabilityDayMap(RESPONSE)

  it('is false for past dates regardless of availability', () => {
    expect(isDateSelectable(map, '2026-08-01', true)).toBe(false)
  })

  it('is false for dates outside the loaded range', () => {
    expect(isDateSelectable(map, '2026-09-01', false)).toBe(false)
  })

  it('is true only when the day is open AND available', () => {
    expect(isDateSelectable(map, '2026-08-01', false)).toBe(true)
    expect(isDateSelectable(map, '2026-08-02', false)).toBe(false)
    expect(isDateSelectable(map, '2026-08-04', false)).toBe(false)
  })
})

describe('availabilityReasonLabel', () => {
  it('maps each structural reason to Spanish copy', () => {
    expect(
      availabilityReasonLabel({
        date: 'x',
        open: false,
        reason: 'closed_weekday',
        available: false,
      })
    ).toBe('La amenidad no opera este día')
    expect(
      availabilityReasonLabel({ date: 'x', open: false, reason: 'blackout', available: false })
    ).toBe('Bloqueado por el administrador')
    expect(
      availabilityReasonLabel({
        date: 'x',
        open: false,
        reason: 'out_of_service',
        available: false,
      })
    ).toBe('Fuera de servicio')
  })

  it('says "sin cupo" when open but not available (no free slots)', () => {
    expect(availabilityReasonLabel({ date: 'x', open: true, reason: null, available: false })).toBe(
      'Sin cupo disponible'
    )
  })

  it('says "Disponible" when open and available', () => {
    expect(availabilityReasonLabel({ date: 'x', open: true, reason: null, available: true })).toBe(
      'Disponible'
    )
  })

  it('handles an undefined day (not yet loaded)', () => {
    expect(availabilityReasonLabel(undefined)).toBe('No disponible')
  })
})

describe('freeSlotsForDay', () => {
  const daySlots: IAmenityAvailabilityDay = {
    date: '2026-08-01',
    open: true,
    reason: null,
    available: true,
    slots: [
      {
        slot_id: 'slot-1',
        weekday: 6,
        start_time: '10:00',
        end_time: '11:00',
        start_at: '2026-08-01T16:00:00Z',
        end_at: '2026-08-01T17:00:00Z',
        capacity: 4,
        taken: 1,
        free: 3,
      },
    ],
  }

  it('returns the slots array when present', () => {
    expect(freeSlotsForDay(daySlots)).toHaveLength(1)
  })

  it('returns an empty array when the day has no slots (by_day mode) or is undefined', () => {
    expect(
      freeSlotsForDay({ date: '2026-08-01', open: true, reason: null, available: true })
    ).toEqual([])
    expect(freeSlotsForDay(undefined)).toEqual([])
  })
})
