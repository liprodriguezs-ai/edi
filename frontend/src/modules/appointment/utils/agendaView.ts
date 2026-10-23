import type { IAppointment } from '@/modules/appointment/schema'
import { appointmentStatusKey, isActiveOnAgenda } from '@/modules/appointment/utils/appointmentStatus'
import {
  addCivilDays,
  civilDateToWallDate,
  civilWeekBounds,
  compareInstants,
  instantToTenantCivilDate,
} from '@/shared/utils/tenantTime'
import { format } from 'date-fns'
import { es } from 'date-fns/locale'
import { Temporal } from 'temporal-polyfill'

/** Vistas de la agenda: las tres del calendario más la lista agrupada por día. */
export type AgendaView = 'day' | 'week' | 'month' | 'list'

/** Días que abarca la vista de lista, empezando en el día a la vista. */
const LIST_DAYS = 14

interface CivilRange {
  from: string
  to: string
}

/** Días civiles (inclusivos) que hay que cargar para pintar la vista. */
export function agendaRange(view: AgendaView, day: string): CivilRange {
  if (view === 'day') return { from: day, to: day }
  if (view === 'week') return civilWeekBounds(day)
  if (view === 'list') return { from: day, to: addCivilDays(day, LIST_DAYS - 1) }

  // Month: the whole grid, from the Monday before the 1st to the Sunday after the last day.
  const date = Temporal.PlainDate.from(day)
  const first = date.with({ day: 1 }).toString()
  const last = date.with({ day: date.daysInMonth }).toString()
  return { from: civilWeekBounds(first).from, to: civilWeekBounds(last).to }
}

/** Día al que llevan las flechas: un día, una semana, un mes o un bloque de lista. */
export function shiftAgendaDay(view: AgendaView, day: string, direction: 1 | -1): string {
  if (view === 'day') return addCivilDays(day, direction)
  if (view === 'week') return addCivilDays(day, 7 * direction)
  if (view === 'list') return addCivilDays(day, LIST_DAYS * direction)
  return Temporal.PlainDate.from(day).add({ months: direction }).toString()
}

function fmt(day: string, pattern: string): string {
  return format(civilDateToWallDate(day), pattern, { locale: es })
}

function rangeHeading(from: string, to: string): string {
  const sameYear = from.slice(0, 4) === to.slice(0, 4)
  const sameMonth = sameYear && from.slice(0, 7) === to.slice(0, 7)
  if (sameMonth) return `${fmt(from, 'd')} – ${fmt(to, "d 'de' MMMM 'de' yyyy")}`
  if (sameYear) return `${fmt(from, "d 'de' MMMM")} – ${fmt(to, "d 'de' MMMM 'de' yyyy")}`
  return `${fmt(from, "d 'de' MMMM 'de' yyyy")} – ${fmt(to, "d 'de' MMMM 'de' yyyy")}`
}

/** Título de la vista: el día, la semana, el mes o el bloque de la lista. */
export function agendaHeading(view: AgendaView, day: string): string {
  if (view === 'day') return fmt(day, "EEEE d 'de' MMMM 'de' yyyy")
  if (view === 'month') return fmt(day, "MMMM 'de' yyyy")
  if (view === 'week') {
    const { from, to } = civilWeekBounds(day)
    return rangeHeading(from, to)
  }
  const { from, to } = agendaRange('list', day)
  return rangeHeading(from, to)
}

export interface AgendaDayGroup {
  day: string
  label: string
  appointments: IAppointment[]
}

/** Lista: citas activas agrupadas por día de la clínica, en orden de hora. */
export function groupAppointmentsByDay(appointments: IAppointment[], timeZone: string, today: string): AgendaDayGroup[] {
  const tomorrow = addCivilDays(today, 1)
  const byDay = new Map<string, IAppointment[]>()

  const sorted = appointments.filter(isActiveOnAgenda).sort((a, b) => compareInstants(a.starts_at, b.starts_at))
  for (const appointment of sorted) {
    const day = instantToTenantCivilDate(appointment.starts_at, timeZone)
    byDay.set(day, [...(byDay.get(day) ?? []), appointment])
  }

  return [...byDay.entries()].map(([day, items]) => {
    const name = fmt(day, "EEEE d 'de' MMMM")
    const label = day === today ? `Hoy · ${name}` : day === tomorrow ? `Mañana · ${name}` : name
    return { day, label, appointments: items }
  })
}

export interface AgendaSummary {
  /** Citas del día que siguen en la agenda (sin canceladas). */
  total: number
  fulfilled: number
  /** En sala de espera. */
  waiting: number
  /** Citas de Prueba EDI que todavía no se atienden. */
  pendingScreenings: number
}

const STILL_TO_ATTEND = ['scheduled', 'confirmed', 'arrived']

/** Conteos de un día para el resumen de la agenda. */
export function todaySummary(appointments: IAppointment[]): AgendaSummary {
  const active = appointments.filter(isActiveOnAgenda)
  const withStatus = (key: string) => active.filter((item) => appointmentStatusKey(item) === key).length
  return {
    total: active.length,
    fulfilled: withStatus('fulfilled'),
    waiting: withStatus('arrived'),
    pendingScreenings: active.filter(
      (item) => item.appointment_type?.key === 'screening' && STILL_TO_ATTEND.includes(appointmentStatusKey(item))
    ).length,
  }
}
