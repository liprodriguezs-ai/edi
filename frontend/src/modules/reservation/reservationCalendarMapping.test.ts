import 'temporal-polyfill/global'
import {
  amenityCalendarId,
  amenityColorHex,
  mapReservationToCalendarEvent,
} from '@/modules/reservation/reservationCalendarMapping'
import { RESERVATION_STATUS, type IAmenityReservation } from '@/modules/reservation/schema'
import { describe, expect, it } from 'vitest'

function makeReservation(overrides: Partial<IAmenityReservation> = {}): IAmenityReservation {
  return {
    id: 'reservation-1',
    amenity_id: 'amenity-1',
    unit_id: 'unit-1',
    reservation_date: '2026-08-01',
    reservation_status_id: RESERVATION_STATUS.CONFIRMED,
    amenity: { id: 'amenity-1', name: 'Alberca' },
    unit: { id: 'unit-1', full_identifier: 'Casa 12' },
    ...overrides,
  } as IAmenityReservation
}

describe('mapReservationToCalendarEvent', () => {
  it('maps a by_day reservation (sin start_at/end_at) a un evento de día completo', () => {
    const reservation = makeReservation({ reservation_date: '2026-08-01' })

    const event = mapReservationToCalendarEvent(reservation)

    expect(event.id).toBe('reservation-1')
    expect(event.title).toBe('Alberca — Casa 12')
    expect(event.start).toBeInstanceOf(Temporal.PlainDate)
    expect(event.end).toBeInstanceOf(Temporal.PlainDate)
    expect(event.start.toString()).toBe('2026-08-01')
    expect(event.end.toString()).toBe('2026-08-01')
  })

  it('maps a by_slot reservation (con start_at/end_at) a un evento con horario', () => {
    const reservation = makeReservation({
      reservation_date: '2026-08-02',
      start_at: '2026-08-02T15:00:00.000000Z',
      end_at: '2026-08-02T16:00:00.000000Z',
    })

    const event = mapReservationToCalendarEvent(reservation)

    expect(event.start).toBeInstanceOf(Temporal.ZonedDateTime)
    expect(event.end).toBeInstanceOf(Temporal.ZonedDateTime)
    // El instante UTC se preserva exacto, sin importar a qué huso horario local se
    // convierta la representación de pared (start/end deben seguir siendo el mismo
    // instante que el enviado por el backend).
    expect((event.start as Temporal.ZonedDateTime).toInstant().toString()).toBe(
      Temporal.Instant.from('2026-08-02T15:00:00.000000Z').toString()
    )
  })

  it('usa el nombre de la unidad (number) si no hay full_identifier', () => {
    const reservation = makeReservation({ unit: { id: 'unit-1', number: '12' } })

    const event = mapReservationToCalendarEvent(reservation)

    expect(event.title).toBe('Alberca — 12')
  })

  it('cae a "Amenidad" si no viene la relación amenity', () => {
    const reservation = makeReservation({ amenity: null, unit: null })

    const event = mapReservationToCalendarEvent(reservation)

    expect(event.title).toBe('Amenidad')
  })
})

describe('amenityCalendarId / amenityColorHex', () => {
  it('es determinístico: el mismo id de amenidad siempre resuelve al mismo cupo de color', () => {
    const first = amenityCalendarId('amenity-1')
    const second = amenityCalendarId('amenity-1')

    expect(first).toBe(second)
    expect(amenityColorHex('amenity-1')).toBe(amenityColorHex('amenity-1'))
  })

  it('distingue dos amenidades distintas con calendarId/color distintos', () => {
    const idA = amenityCalendarId('amenity-alberca')
    const idB = amenityCalendarId('amenity-gimnasio')

    expect(idA).not.toBe(idB)
    expect(amenityColorHex('amenity-alberca')).not.toBe(amenityColorHex('amenity-gimnasio'))
  })

  it('el calendarId del evento mapeado corresponde a amenityCalendarId(amenity_id)', () => {
    const reservation = makeReservation({ amenity_id: 'amenity-alberca' })

    const event = mapReservationToCalendarEvent(reservation)

    expect(event.calendarId).toBe(amenityCalendarId('amenity-alberca'))
  })
})
