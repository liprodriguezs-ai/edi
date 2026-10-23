import {
  formatDateOnly,
  formatTenantInstant,
  parseInstant,
  resolveTenantTimeZone,
  tenantCurrentMonth,
  tenantToday,
  toCivilDate,
} from '@/shared/utils/tenantTime'

/**
 * Zona horaria IANA del tenant de la sesión. Estado de módulo escrito por
 * `sessionStore` (login/refresh/restore/clear); la leen los helpers de este
 * archivo para que las ~70 llamadas existentes no tengan que recibir la zona.
 * null = sin tenant → se usa la zona del navegador.
 */
let tenantTimeZone: string | null = null

export const setTenantTimeZone = (timeZone: string | null | undefined): void => {
  tenantTimeZone = timeZone ?? null
}

/** Zona efectiva: la del tenant si es válida; si no, la del navegador. */
export const getTenantTimeZone = (): string => resolveTenantTimeZone(tenantTimeZone)

/**
 * Formatea un valor de la API para mostrar.
 *  - Fecha civil `YYYY-MM-DD` (o medianoche UTC en `mediumDate`): sin corrimiento.
 *  - Instante (`...Z`, con offset o sin offset = UTC): en la zona del tenant.
 */
export const displayFormatDate = (
  dateStr: string | null | undefined,
  formatD: 'medium' | 'mediumDate' = 'medium'
): string => {
  if (!dateStr) {
    return ''
  }

  try {
    const civil = toCivilDate(dateStr)
    if (civil && (formatD === 'mediumDate' || civil === dateStr)) {
      const rendered = formatDateOnly(civil)
      return formatD === 'medium' ? `${rendered} 00:00` : rendered
    }

    if (!parseInstant(dateStr)) {
      return ''
    }
    return formatTenantInstant(
      dateStr,
      getTenantTimeZone(),
      formatD === 'mediumDate' ? 'mediumDate' : 'medium'
    )
  } catch (error) {
    console.error('Error formatting date:', dateStr, error)
    return ''
  }
}

/**
 * Devuelve la fecha de HOY (`yyyy-MM-dd`) en la zona horaria del TENANT — nunca
 * `toISOString()` (UTC) ni el reloj del navegador: a partir de ~18:00 en
 * America/Mexico_City el día UTC ya es "mañana".
 */
export const todayLocalDateString = (): string => tenantToday(getTenantTimeZone())

/** Mes actual (`yyyy-MM`) en la zona horaria del tenant. */
export const currentTenantMonth = (): string => tenantCurrentMonth(getTenantTimeZone())

/**
 * Serializa un `Date` a `yyyy-MM-dd` usando sus componentes LOCALES
 * (getFullYear/getMonth/getDate) — NUNCA `toISOString()` (ver docstring de
 * `todayLocalDateString`, mismo motivo: evita el corrimiento de día en
 * tenants al oeste de UTC). Útil para serializar la fecha elegida en un
 * `<Calendar>`/`DayPicker` antes de mandarla al backend.
 */
export const formatLocalDateString = (date: Date): string => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** Parsea `yyyy-MM-dd` a un `Date` a medianoche LOCAL (inverso de `formatLocalDateString`). */
export const parseLocalDateString = (dateStr: string): Date => {
  const [year, month, day] = dateStr.split('-').map(Number)
  return new Date(year, month - 1, day)
}

/**
 * Trunca un TIME de backend "HH:mm:ss" a "HH:mm" para mostrar en UI (evita
 * pintar segundos irrelevantes, ej. horarios de amenidades: "10:00:00" →
 * "10:00"). Devuelve el valor tal cual si no matchea el patrón esperado.
 */
export const formatTimeHHmm = (value: string | null | undefined): string => {
  if (!value) return ''
  const match = value.match(/^(\d{2}:\d{2})/)
  return match ? match[1] : value
}

/**
 * Valor `yyyy-MM-dd` para un input de fecha. Una fecha civil (o medianoche UTC)
 * se toma tal cual; un instante se convierte al día civil del tenant.
 */
export const formatDateForInput = (dateStr?: string | null): string => {
  if (!dateStr) return ''
  const civil = toCivilDate(dateStr)
  if (civil) return civil
  const instant = parseInstant(dateStr)
  if (instant) return instant.toZonedDateTimeISO(getTenantTimeZone()).toPlainDate().toString()
  // Último recurso (formato desconocido): conservar el prefijo yyyy-MM-dd si existe.
  return /^(\d{4}-\d{2}-\d{2})/.exec(dateStr)?.[1] ?? ''
}
