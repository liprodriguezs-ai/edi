import type { Month } from 'date-fns'
import { es } from 'date-fns/locale'
import { Temporal } from 'temporal-polyfill'

/**
 * Única fuente de verdad del manejo de fechas/horas del frontend.
 *
 * Convención (ver odd/tasks/tenant-timezone-utc.md, S4/S5):
 *  - INSTANTES (created_at, scored_at, expires_at, ...) llegan de la API en UTC
 *    ISO-8601 con `Z` y se muestran en la zona horaria del TENANT, nunca en la
 *    del navegador.
 *  - FECHAS CIVILES (birth_date, visit_date, due_date, ...) son `YYYY-MM-DD` y
 *    JAMÁS se desplazan por ninguna zona.
 *
 * Todas las funciones son puras y reciben la zona explícita; el estado de "zona
 * actual" vive en `dateHelper.ts` (alimentado por la sesión).
 */

export type TenantInstantFormat = 'medium' | 'mediumDate'
export type TenantDatePreset = 'overdue' | 'today' | 'this_week' | 'this_month'
export type TenantDateTimeError = 'invalid_datetime' | 'invalid_timezone' | 'invalid_wall_time'
export type TenantDateTimeResult =
  | { ok: true; value: string }
  | { ok: false; error: TenantDateTimeError }

const CIVIL_DATE = /^\d{4}-\d{2}-\d{2}$/
/** Fecha civil serializada por error como medianoche UTC (`2026-10-21T00:00:00.000000Z`). */
const MIDNIGHT_UTC_DATE = /^(\d{4}-\d{2}-\d{2})T00:00(?::00(?:\.0+)?)?Z$/
/** Datetime sin offset (`2026-10-21 03:00:00`): el almacenamiento es UTC, así que se lee como UTC. */
const OFFSETLESS_DATETIME = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?$/
const HAS_OFFSET = /(?:Z|[+-]\d{2}(?::?\d{2})?)$/i

const pad = (value: number): string => String(value).padStart(2, '0')

const nowIso = (): string => Temporal.Now.instant().toString()

export function isValidTimeZone(timeZone: string | null | undefined): timeZone is string {
  if (!timeZone) return false
  try {
    new Intl.DateTimeFormat('en', { timeZone })
    return true
  } catch {
    return false
  }
}

export function browserTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone
}

/** Zona del tenant si es válida; si falta o es inválida, la del navegador. */
export function resolveTenantTimeZone(
  timeZone?: string | null,
  fallback: string = browserTimeZone()
): string {
  return isValidTimeZone(timeZone) ? timeZone : fallback
}

/**
 * Devuelve la fecha civil `YYYY-MM-DD` de un valor date-only, o null si el valor
 * es un instante con hora. Defensivo: acepta la medianoche UTC como fecha civil.
 */
export function toCivilDate(value: string): string | null {
  if (CIVIL_DATE.test(value)) return value
  const midnight = MIDNIGHT_UTC_DATE.exec(value)
  return midnight ? midnight[1] : null
}

/** Parsea un instante (con `Z`/offset, o sin offset = UTC). Null si no es válido. */
export function parseInstant(value: string): Temporal.Instant | null {
  const trimmed = value.trim()
  try {
    if (HAS_OFFSET.test(trimmed) && trimmed.length > 10) return Temporal.Instant.from(trimmed)
    if (OFFSETLESS_DATETIME.test(trimmed))
      return Temporal.Instant.from(`${trimmed.replace(' ', 'T')}Z`)
  } catch {
    return null
  }
  return null
}

function renderCivilDate(date: Temporal.PlainDate): string {
  const month = es.localize.month((date.month - 1) as Month, { width: 'abbreviated' })
  return `${month} ${date.day}, ${date.year}`
}

/** Formatea una fecha civil estricta `YYYY-MM-DD` (`oct 21, 2026`), sin zona. */
export function formatDateOnly(value: string): string {
  if (!CIVIL_DATE.test(value)) throw new RangeError('Expected an ISO civil date')
  return renderCivilDate(Temporal.PlainDate.from(value, { overflow: 'reject' }))
}

/** Formatea un instante en la zona del tenant (`oct 20, 2026 21:00`). */
export function formatTenantInstant(
  value: string,
  timeZone: string,
  format: TenantInstantFormat = 'medium'
): string {
  const instant = parseInstant(value)
  if (!instant) throw new RangeError('Expected an ISO instant')
  const date = instant.toZonedDateTimeISO(timeZone)
  const renderedDate = renderCivilDate(date.toPlainDate())
  if (format === 'mediumDate') return renderedDate
  return `${renderedDate} ${pad(date.hour)}:${pad(date.minute)}`
}

/** Hoy (`YYYY-MM-DD`) en la zona del tenant. */
export function tenantToday(timeZone: string, now: string = nowIso()): string {
  return Temporal.Instant.from(now).toZonedDateTimeISO(timeZone).toPlainDate().toString()
}

/** Mes actual (`YYYY-MM`) en la zona del tenant. */
export function tenantCurrentMonth(timeZone: string, now: string = nowIso()): string {
  return tenantToday(timeZone, now).slice(0, 7)
}

/** Suma días calendario a una fecha civil (nunca múltiplos de 24 h). */
export function addCivilDays(date: string, days: number): string {
  return Temporal.PlainDate.from(date, { overflow: 'reject' }).add({ days }).toString()
}

/** Valor para `<input type="datetime-local">` (precisión de minutos) en la zona del tenant. */
export function utcToTenantDateTimeLocal(value: string, timeZone: string): string {
  const instant = parseInstant(value)
  if (!instant) throw new RangeError('Expected an ISO instant')
  return instant.toZonedDateTimeISO(timeZone).toPlainDateTime().toString({ smallestUnit: 'minute' })
}

/** Hora de pared del tenant → instante UTC. Rechaza huecos y pliegues de DST en vez de adivinar. */
export function tenantDateTimeLocalToUtc(value: string, timeZone: string): TenantDateTimeResult {
  if (!isValidTimeZone(timeZone)) return { ok: false, error: 'invalid_timezone' }
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?$/.test(value)) {
    return { ok: false, error: 'invalid_datetime' }
  }
  let date: Temporal.PlainDateTime
  try {
    date = Temporal.PlainDateTime.from(value, { overflow: 'reject' })
  } catch {
    return { ok: false, error: 'invalid_datetime' }
  }
  try {
    return {
      ok: true,
      value: date.toZonedDateTime(timeZone, { disambiguation: 'reject' }).toInstant().toString(),
    }
  } catch {
    return { ok: false, error: 'invalid_wall_time' }
  }
}

/** Rangos civiles inclusivos; `overdue` termina ayer y la semana empieza en lunes. */
export function tenantDatePreset(
  preset: TenantDatePreset,
  timeZone: string,
  now: string = nowIso()
): { from: string | null; to: string } {
  const today = Temporal.PlainDate.from(tenantToday(timeZone, now))
  switch (preset) {
    case 'overdue':
      return { from: null, to: today.subtract({ days: 1 }).toString() }
    case 'today':
      return { from: today.toString(), to: today.toString() }
    case 'this_week': {
      const start = today.subtract({ days: today.dayOfWeek - 1 })
      return { from: start.toString(), to: start.add({ days: 6 }).toString() }
    }
    case 'this_month': {
      const start = today.with({ day: 1 })
      return { from: start.toString(), to: start.with({ day: today.daysInMonth }).toString() }
    }
  }
}

/** Epoch de un instante; una fecha civil suelta cuenta como su medianoche UTC (igual que `Date.parse`). */
function epochMs(value: string): number {
  const instant = parseInstant(CIVIL_DATE.test(value) ? `${value}T00:00:00Z` : value)
  return instant?.epochMilliseconds ?? Number.NaN
}

/** Comparador de instantes para `sort` (valores inválidos quedan al principio). */
export function compareInstants(a: string, b: string): number {
  const left = epochMs(a)
  const right = epochMs(b)
  if (Number.isNaN(left) || Number.isNaN(right))
    return Number.isNaN(left) ? (Number.isNaN(right) ? 0 : -1) : 1
  return left - right
}

/** true si el instante es igual o posterior a `now`. Un valor inválido nunca está vigente. */
export function isInstantAtOrAfter(value: string, now: string = nowIso()): boolean {
  const target = epochMs(value)
  return !Number.isNaN(target) && target >= epochMs(now)
}

/** Segundos unix (Stripe) → instante ISO UTC. */
export function unixSecondsToInstant(seconds: number): string {
  return Temporal.Instant.fromEpochMilliseconds(seconds * 1000).toString()
}

/**
 * Calendarios como react-big-calendar operan con `Date` en la zona del NAVEGADOR.
 * Para mostrar la agenda en la zona del TENANT se usan fechas "de pared": un
 * `Date` cuyos componentes locales (año…minuto) son la hora del tenant. Sólo
 * deben vivir dentro del calendario; a la API siempre viaja el instante UTC.
 */
export function instantToTenantWallDate(value: string, timeZone: string): Date {
  const instant = parseInstant(value)
  if (!instant) throw new RangeError('Expected an ISO instant')
  const zoned = instant.toZonedDateTimeISO(timeZone)
  return new Date(zoned.year, zoned.month - 1, zoned.day, zoned.hour, zoned.minute, zoned.second)
}

/** Fecha de pared del calendario → instante UTC (rechaza huecos/pliegues de DST). */
export function tenantWallDateToUtc(date: Date, timeZone: string): TenantDateTimeResult {
  const local = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
  return tenantDateTimeLocalToUtc(local, timeZone)
}

/** Fecha de pared → fecha civil `YYYY-MM-DD`. */
export function wallDateToCivilDate(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** Fecha civil `YYYY-MM-DD` → fecha de pared a medianoche. */
export function civilDateToWallDate(value: string): Date {
  const date = Temporal.PlainDate.from(value, { overflow: 'reject' })
  return new Date(date.year, date.month - 1, date.day)
}

/** "Ahora" como fecha de pared del tenant (línea de hora actual del calendario). */
export function tenantNowWallDate(timeZone: string, now: string = nowIso()): Date {
  return instantToTenantWallDate(now, timeZone)
}

/** Hora `HH:mm` de un instante en la zona del tenant (agenda, sala de espera). */
export function formatTenantTime(value: string, timeZone: string): string {
  const instant = parseInstant(value)
  if (!instant) throw new RangeError('Expected an ISO instant')
  const zoned = instant.toZonedDateTimeISO(timeZone)
  return `${pad(zoned.hour)}:${pad(zoned.minute)}`
}

/** Fecha civil del tenant (`YYYY-MM-DD`) en la que cae un instante. */
export function instantToTenantCivilDate(value: string, timeZone: string): string {
  const instant = parseInstant(value)
  if (!instant) throw new RangeError('Expected an ISO instant')
  return instant.toZonedDateTimeISO(timeZone).toPlainDate().toString()
}

/** Límites UTC `[inicio, fin)` de un rango de días civiles del tenant (fin inclusivo como día). */
export function tenantCivilRangeToUtc(from: string, to: string, timeZone: string): { start: string; end: string } {
  const startDate = Temporal.PlainDate.from(from, { overflow: 'reject' })
  const endDate = Temporal.PlainDate.from(to, { overflow: 'reject' }).add({ days: 1 })
  return {
    start: startDate.toZonedDateTime({ timeZone }).toInstant().toString(),
    end: endDate.toZonedDateTime({ timeZone }).toInstant().toString(),
  }
}

/** Hora del día como fecha de pared (sólo importa la hora): límites visibles de un calendario. */
export function wallClock(hour: number, minute = 0): Date {
  return new Date(1970, 0, 1, hour, minute)
}

/** Días civiles completos de `from` a `to` (edad en días a partir de la fecha de nacimiento). */
export function civilDaysBetween(from: string, to: string): number {
  return Temporal.PlainDate.from(from).until(Temporal.PlainDate.from(to), { largestUnit: 'days' }).days
}

/** Semana civil (lunes a domingo) que contiene la fecha dada. */
export function civilWeekBounds(value: string): { from: string; to: string } {
  const date = Temporal.PlainDate.from(value, { overflow: 'reject' })
  const start = date.subtract({ days: date.dayOfWeek - 1 })
  return { from: start.toString(), to: start.add({ days: 6 }).toString() }
}

/** Fecha de pared → valor de `<input type="datetime-local">` (`YYYY-MM-DDTHH:mm`). */
export function wallDateToDateTimeLocal(date: Date): string {
  return `${wallDateToCivilDate(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
