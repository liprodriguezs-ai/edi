import { format, parseISO } from 'date-fns'
import { es } from 'date-fns/locale'

export const displayFormatDate = (
  dateStr: string | null | undefined,
  formatD: 'medium' | 'mediumDate' = 'medium'
) => {
  if (!dateStr) {
    return ''
  }

  try {
    // Parse ISO string which handles timezone correctly
    let date = parseISO(dateStr)

    // If parsing fails, try with Date constructor
    if (isNaN(date.getTime())) {
      date = new Date(dateStr)
    }

    // Validate the date
    if (isNaN(date.getTime())) {
      return ''
    }

    // For date-only formatting, adjust to local timezone to prevent day shift
    // This ensures "2025-11-25T00:00:00.000Z" displays as Nov 25, not Nov 24
    if (formatD === 'mediumDate') {
      // Extract only the date parts (YYYY-MM-DD) and create a local date
      // Handles both ISO format (with T) and simple date format
      const dateMatch = dateStr.match(/^(\d{4}-\d{2}-\d{2})/)
      if (dateMatch && dateMatch[1]) {
        const [year, month, day] = dateMatch[1].split('-').map(Number)
        // Create date in local timezone to prevent UTC to local conversion issues
        date = new Date(year, month - 1, day)
      }
    }

    switch (formatD) {
      case 'medium':
        return format(date, 'MMM d, yyyy HH:mm', { locale: es })
      case 'mediumDate':
        return format(date, 'MMM d, yyyy', { locale: es })
      default:
        return format(date, 'MMM d, yyyy HH:mm', { locale: es })
    }
  } catch (error) {
    console.error('Error formatting date:', dateStr, error)
    return ''
  }
}

/**
 * Devuelve la fecha de HOY en formato yyyy-MM-dd usando el reloj LOCAL del
 * navegador (getFullYear/getMonth/getDate) — NUNCA `toISOString()`. Los
 * tenants de Kivo7 corren en America/Mexico_City (UTC-6): `toISOString()`
 * serializa en UTC, así que a partir de ~18:00 hora local el día ya cambió a
 * "mañana" en UTC y el default de fecha queda un día adelantado.
 */
export const todayLocalDateString = (): string => formatLocalDateString(new Date())

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

export const formatDateForInput = (dateStr?: string) => {
  if (!dateStr) return ''
  try {
    const dateMatch = dateStr.match(/^(\d{4}-\d{2}-\d{2})/)
    if (dateMatch?.[1]) {
      return dateMatch[1]
    }
    return format(parseISO(dateStr), 'yyyy-MM-dd')
  } catch (error) {
    console.error('Error parsing date:', dateStr, error)
    return ''
  }
}
