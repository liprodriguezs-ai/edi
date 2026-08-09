import { addDays, format } from 'date-fns'
import { es } from 'date-fns/locale'
import { MONTH_NAMES } from '@/modules/chargeBatch/constants'
import type { IChargeBatch } from '@/modules/chargeBatch/schema'
import { PERIODICITY } from '@/modules/chargeConcept/schema'

/** Separador EN DASH (U+2013) del rango — el mismo que usa el label del backend. */
const RANGE_DASH = '–'

/** Parsea un YYYY-MM-DD a Date LOCAL (evita el corrimiento de día por UTC). */
function localDateFromYmd(value: string): Date | null {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!match) return null
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

/**
 * Etiqueta del periodo de un lote.
 *
 * ANCHORED BILLING PERIODS: cuando el lote trae `period_start` (todo lote nuevo, generado
 * bajo el motor anclado), la etiqueta es el rango real "15 jul – 14 ago 2026" — MIRRORS
 * `PeriodicityScheduleResolver::rangeLabel` del backend (mismo formato, mismo EN DASH).
 *
 * `period_start = null` marca un lote LEGACY (rejilla calendario pre-cutover) o una
 * plantilla: la reconstrucción por año/mes/ciclo se conserva VERBATIM, sin cambios.
 */
export function periodLabel(batch: IChargeBatch, periodicityId?: string | null): string {
  if (batch.period_start && batch.due_date) {
    const start = localDateFromYmd(batch.period_start)
    const end = localDateFromYmd(batch.due_date)
    if (start && end) {
      return `${format(start, 'd MMM', { locale: es })} ${RANGE_DASH} ${format(end, 'd MMM yyyy', { locale: es })}`
    }
  }

  if (periodicityId === PERIODICITY.EVERY_TWO_DAYS && batch.month && batch.cycle) {
    const start = new Date(batch.year, batch.month - 1, batch.cycle)
    const end = addDays(start, 1)
    return `${format(start, 'dd/MM')}${RANGE_DASH}${format(end, 'dd/MM/yyyy')}`
  }
  if (batch.month) return `${MONTH_NAMES[batch.month - 1]} ${batch.year}`
  if (batch.year) return String(batch.year)
  return 'Eventual'
}
