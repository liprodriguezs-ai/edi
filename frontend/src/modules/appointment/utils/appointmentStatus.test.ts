import type { AppointmentStatusKey, IAppointment } from '@/modules/appointment/schema'
import { availableActions, isActiveOnAgenda } from '@/modules/appointment/utils/appointmentStatus'
import { describe, expect, it } from 'vitest'

function appointmentWith(key: AppointmentStatusKey): IAppointment {
  return {
    id: 'a-1',
    child_id: 'c-1',
    health_center_id: 'h-1',
    practitioner_id: 'p-1',
    appointment_type_id: 't-1',
    appointment_status_id: 's-1',
    starts_at: '2026-10-21T16:00:00Z',
    ends_at: '2026-10-21T16:30:00Z',
    is_walk_in: false,
    appointment_status: { id: 's-1', key, name: key },
  }
}

describe('availableActions', () => {
  it('ofrece confirmar, reprogramar y cancelar a una cita agendada de otro día', () => {
    expect(availableActions(appointmentWith('scheduled'), false, false)).toEqual({
      confirm: true,
      arrive: false,
      start: false,
      reschedule: true,
      cancel: true,
      noShow: false,
    })
  })

  it('permite "Llegó" e iniciar atención sólo el día de la cita', () => {
    const actions = availableActions(appointmentWith('confirmed'), true, false)
    expect(actions.arrive).toBe(true)
    expect(actions.start).toBe(true)
    expect(actions.confirm).toBe(false)
  })

  it('en sala de espera sólo deja iniciar o cancelar', () => {
    expect(availableActions(appointmentWith('arrived'), true, true)).toEqual({
      confirm: false,
      arrive: false,
      start: true,
      reschedule: false,
      cancel: true,
      noShow: false,
    })
  })

  it('"No asistió" sólo cuando la hora ya pasó', () => {
    expect(availableActions(appointmentWith('scheduled'), true, false).noShow).toBe(false)
    expect(availableActions(appointmentWith('scheduled'), true, true).noShow).toBe(true)
  })

  it('las citas cerradas no admiten acciones', () => {
    for (const key of ['fulfilled', 'cancelled', 'no_show'] as const) {
      expect(Object.values(availableActions(appointmentWith(key), true, true)).some(Boolean)).toBe(false)
    }
  })
})

describe('isActiveOnAgenda', () => {
  it('oculta sólo las canceladas', () => {
    expect(isActiveOnAgenda(appointmentWith('cancelled'))).toBe(false)
    expect(isActiveOnAgenda(appointmentWith('no_show'))).toBe(true)
  })
})
