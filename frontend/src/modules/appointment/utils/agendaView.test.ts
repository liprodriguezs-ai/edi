import type { IAppointment } from '@/modules/appointment/schema'
import {
  agendaHeading,
  agendaRange,
  groupAppointmentsByDay,
  shiftAgendaDay,
  todaySummary,
} from '@/modules/appointment/utils/agendaView'
import { describe, expect, it } from 'vitest'

const TZ = 'America/Mexico_City'

function appointment(id: string, startsAt: string, status: string, type = 'check_up'): IAppointment {
  return {
    id,
    child_id: `c-${id}`,
    starts_at: startsAt,
    ends_at: startsAt,
    appointment_status: { id: `st-${status}`, key: status, name: status },
    appointment_type: { id: `ty-${type}`, key: type, name: type, default_duration_minutes: 30 },
  } as unknown as IAppointment
}

describe('agendaRange', () => {
  it('loads a single day, the Monday-to-Sunday week, or the whole month grid', () => {
    expect(agendaRange('day', '2026-10-21')).toEqual({ from: '2026-10-21', to: '2026-10-21' })
    expect(agendaRange('week', '2026-10-21')).toEqual({ from: '2026-10-19', to: '2026-10-25' })
    // October 2026 starts on a Thursday and ends on a Saturday.
    expect(agendaRange('month', '2026-10-21')).toEqual({ from: '2026-09-28', to: '2026-11-01' })
  })

  it('lists the viewed day plus the next 13 days', () => {
    expect(agendaRange('list', '2026-10-21')).toEqual({ from: '2026-10-21', to: '2026-11-03' })
  })
})

describe('shiftAgendaDay', () => {
  it('moves by the size of the view', () => {
    expect(shiftAgendaDay('day', '2026-10-21', 1)).toBe('2026-10-22')
    expect(shiftAgendaDay('week', '2026-10-21', -1)).toBe('2026-10-14')
    expect(shiftAgendaDay('month', '2026-10-31', 1)).toBe('2026-11-30')
    expect(shiftAgendaDay('list', '2026-10-21', 1)).toBe('2026-11-04')
  })
})

describe('agendaHeading', () => {
  it('names what the view shows, in Spanish', () => {
    expect(agendaHeading('day', '2026-10-21')).toBe('miércoles 21 de octubre de 2026')
    expect(agendaHeading('week', '2026-10-21')).toBe('19 – 25 de octubre de 2026')
    expect(agendaHeading('month', '2026-10-21')).toBe('octubre de 2026')
    expect(agendaHeading('list', '2026-10-21')).toBe('21 de octubre – 3 de noviembre de 2026')
  })
})

describe('groupAppointmentsByDay', () => {
  it('groups by the clinic day, labels today and tomorrow, and drops cancelled ones', () => {
    const groups = groupAppointmentsByDay(
      [
        appointment('b', '2026-10-22T15:00:00Z', 'scheduled'),
        appointment('a', '2026-10-21T15:00:00Z', 'arrived'),
        appointment('x', '2026-10-21T16:00:00Z', 'cancelled'),
        // 01:00 UTC on the 24th is still the 23rd in Mexico City.
        appointment('c', '2026-10-24T01:00:00Z', 'scheduled'),
      ],
      TZ,
      '2026-10-21'
    )

    expect(groups.map((group) => group.label)).toEqual([
      'Hoy · miércoles 21 de octubre',
      'Mañana · jueves 22 de octubre',
      'viernes 23 de octubre',
    ])
    expect(groups.map((group) => group.appointments.map((item) => item.id))).toEqual([['a'], ['b'], ['c']])
  })
})

describe('todaySummary', () => {
  it('counts today by status, and EDI tests still to apply', () => {
    const summary = todaySummary([
      appointment('1', '2026-10-21T14:00:00Z', 'fulfilled', 'screening'),
      appointment('2', '2026-10-21T15:00:00Z', 'arrived', 'screening'),
      appointment('3', '2026-10-21T16:00:00Z', 'scheduled', 'check_up'),
      appointment('4', '2026-10-21T17:00:00Z', 'confirmed', 'screening'),
      appointment('5', '2026-10-21T18:00:00Z', 'cancelled', 'screening'),
      appointment('6', '2026-10-21T19:00:00Z', 'no_show', 'screening'),
    ])

    expect(summary).toEqual({ total: 5, fulfilled: 1, waiting: 1, pendingScreenings: 2 })
  })
})
