import type { AppointmentStatusKey, IAppointment } from '@/modules/appointment/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'

/**
 * Tono de la etiqueta de estado. Los tonos g/y/r son el semáforo CLÍNICO y no se
 * usan para estados administrativos: la agenda sólo usa marca (b) y neutro (n).
 */
export function appointmentStatusTone(key?: AppointmentStatusKey | null): PillTone {
  return key === 'confirmed' || key === 'arrived' ? 'b' : 'n'
}

export function appointmentStatusKey(appointment: IAppointment): AppointmentStatusKey {
  return appointment.appointment_status?.key ?? 'scheduled'
}

const OPEN: AppointmentStatusKey[] = ['scheduled', 'confirmed']

/** Acciones que el servidor aceptará para el estado actual (el servidor sigue siendo la autoridad). */
export interface AppointmentAvailableActions {
  confirm: boolean
  arrive: boolean
  start: boolean
  reschedule: boolean
  cancel: boolean
  noShow: boolean
}

export function availableActions(
  appointment: IAppointment,
  isToday: boolean,
  hasStarted: boolean
): AppointmentAvailableActions {
  const key = appointmentStatusKey(appointment)
  const open = OPEN.includes(key)

  return {
    confirm: key === 'scheduled',
    arrive: open && isToday,
    start: key === 'arrived' || (open && isToday),
    reschedule: open,
    cancel: open || key === 'arrived',
    noShow: open && hasStarted,
  }
}

/** Una cita sigue ocupando el horario mientras no esté cerrada. */
export function isActiveOnAgenda(appointment: IAppointment): boolean {
  const key = appointmentStatusKey(appointment)
  return key !== 'cancelled'
}
