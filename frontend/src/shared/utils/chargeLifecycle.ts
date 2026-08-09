import { addDays, addMonths, addYears, format, parseISO } from 'date-fns'
import { PERIODICITY } from '@/modules/chargeConcept/schema'

/**
 * Ciclo de vida de un cargo (frontend).
 *
 * MIRRORS el contrato del backend `PeriodicityScheduleResolver` (límites de
 * período por periodicidad) + `ChargeStatusService` (transiciones temporales
 * pending → due_soon → overdue). Un cargo cubre un PERÍODO; la fecha de
 * VENCIMIENTO es el FIN del período (intrínseca a la periodicidad).
 *
 * Contrato de settings globales por tenant (slugs en GET /config/collection-settings):
 *   - collections.charge_generation_day (def 1): día del período en que nace el cargo.
 *   - collections.due_soon_days (def 7): días antes del fin del período → "por vencer".
 * Por concepto (columna charge_concept.grace_days): días después del fin del
 * período → "moroso".
 *
 * Hitos:
 *   - generationDate = inicio + (generationDay − 1), acotado ≤ fin del período
 *   - dueDate (VENCE)        = fin del período
 *   - dueSoonDate (POR VENCER) = fin − due_soon_days (acotado ≥ generationDate)
 *   - overdueDate (MOROSO)   = fin + graceDays
 */

const GENERATION_DAY_SLUG = 'collections.charge_generation_day'
const DUE_SOON_DAYS_SLUG = 'collections.due_soon_days'

const DEFAULT_GENERATION_DAY = 1
const MIN_GENERATION_DAY = 1
const MAX_GENERATION_DAY = 28

const DEFAULT_DUE_SOON_DAYS = 7
const MIN_DUE_SOON_DAYS = 0
const MAX_DUE_SOON_DAYS = 60

export const GRACE_DAYS_SLUG = 'collections.grace_days'
export const DEFAULT_GRACE_DAYS = 5
const MIN_GRACE_DAYS = 0
const MAX_GRACE_DAYS = 60

export interface LifecycleMilestones {
  generationDate: string
  dueSoonDate: string
  dueDate: string
  overdueDate: string
}

export interface ConcreteLifecycleMilestones {
  generationDate: string
  dueSoonDate: string
  dueDate: string
  overdueDate: string
}

export type LifecycleStage = 'pending' | 'due_soon' | 'overdue'

export interface TimingSettings {
  generationDay: number
  dueSoonDays: number
  graceDays: number
}

interface PeriodBounds {
  start: Date
  end: Date
}

/** Acota un número entero a [min, max], cayendo en `fallback` si no es finito. */
function clampInt(value: unknown, min: number, max: number, fallback: number): number {
  const raw = Number(value)
  if (!Number.isFinite(raw)) {
    return fallback
  }
  return Math.max(min, Math.min(max, Math.trunc(raw)))
}

/** Crea una fecha local (sin desfase UTC) a partir de año/mes(0-based)/día. */
function localDate(year: number, monthIndex: number, day: number): Date {
  return new Date(year, monthIndex, day)
}

/**
 * Normaliza una fecha a medianoche local. date-fns `endOfMonth`/`endOfWeek`
 * devuelven 23:59:59.999; los límites del período son fechas de calendario, no
 * instantes, así que las llevamos a 00:00 para que la aritmética y las
 * comparaciones queden limpias.
 */
function atMidnight(date: Date): Date {
  return localDate(date.getFullYear(), date.getMonth(), date.getDate())
}

/** Formatea una fecha local a YYYY-MM-DD (nunca toISOString, que aplica UTC). */
function toYmd(date: Date): string {
  return format(date, 'yyyy-MM-dd')
}

/** Parsea una fecha ancla (YYYY-MM-DD o ISO) a Date local; hoy si no se da. */
function resolveAnchor(anchorDate?: string): Date {
  if (!anchorDate) {
    return new Date()
  }
  const match = anchorDate.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (match) {
    return localDate(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  }
  const parsed = parseISO(anchorDate)
  return Number.isNaN(parsed.getTime()) ? new Date() : parsed
}

/**
 * ANCHORED BILLING PERIODS — límites del período que ARRANCA en `periodStart` (no el que lo
 * contiene). MIRRORS `PeriodicityScheduleResolver::periodFor` del backend: UNA sola fórmula
 * para las seis cadencias automáticas, fin = (inicio + intervalo) − 1 día. La suma de meses y
 * años es "overflow-safe" (date-fns `addMonths`/`addYears` acotan igual que los *NoOverflow de
 * Carbon — 31-ene + 1 mes → 28/29-feb, nunca marzo). Por construcción el siguiente período
 * arranca justo donde termina este, sin huecos ni solapes.
 *
 * NINGUNA cadencia arranca antes de su ancla. Las formas de rejilla de calendario previas
 * (mitades quincenales; ventanas de 2 días ancladas al epoch Unix) se eliminaron el
 * 2026-07-25 porque podían abrir el período ANTES de la vigencia de la regla. "Quincenal"
 * anclado son 15 días corridos: no existe forma anclada que reproduzca la quincena de
 * calendario mexicana (1→15 / 16→fin de mes) sin arrancar antes del ancla.
 */
export function anchoredPeriodBounds(
  periodicityId: string | null | undefined,
  periodStart: Date
): PeriodBounds {
  const start = atMidnight(periodStart)

  switch (periodicityId) {
    case PERIODICITY.YEARLY:
      return { start, end: atMidnight(addDays(addYears(start, 1), -1)) }

    case PERIODICITY.BIMONTHLY:
      return { start, end: atMidnight(addDays(addMonths(start, 2), -1)) }

    case PERIODICITY.WEEKLY:
      return { start, end: addDays(start, 6) }

    case PERIODICITY.BIWEEKLY:
      return { start, end: addDays(start, 14) }

    case PERIODICITY.EVERY_TWO_DAYS:
      return { start, end: addDays(start, 1) }

    case PERIODICITY.MONTHLY:
    default:
      return { start, end: atMidnight(addDays(addMonths(start, 1), -1)) }
  }
}

interface ComputeMilestonesInput {
  periodicityId: string | null | undefined
  generationDay: number
  dueSoonDays: number
  graceDays: number
  anchorDate?: string
}

/**
 * Hitos paramétricos del ciclo de vida para una periodicidad + settings.
 * Devuelve fechas YYYY-MM-DD. MIRRORS ChargeStatusService.
 */
export function computeLifecycleMilestones({
  periodicityId,
  generationDay,
  dueSoonDays,
  graceDays,
  anchorDate,
}: ComputeMilestonesInput): LifecycleMilestones {
  const anchor = resolveAnchor(anchorDate)
  const { start, end } = anchoredPeriodBounds(periodicityId, anchor)

  const safeGenerationDay = clampInt(
    generationDay,
    MIN_GENERATION_DAY,
    MAX_GENERATION_DAY,
    DEFAULT_GENERATION_DAY
  )
  const safeDueSoonDays = clampInt(
    dueSoonDays,
    MIN_DUE_SOON_DAYS,
    MAX_DUE_SOON_DAYS,
    DEFAULT_DUE_SOON_DAYS
  )
  const safeGraceDays = Math.max(0, Math.trunc(Number(graceDays) || 0))

  // generationDate = inicio + (generationDay − 1), acotado ≤ fin del período
  let generation = addDays(start, safeGenerationDay - 1)
  if (generation.getTime() > end.getTime()) {
    generation = end
  }

  // dueSoonDate = fin − due_soon_days, acotado ≥ generationDate
  let dueSoon = addDays(end, -safeDueSoonDays)
  if (dueSoon.getTime() < generation.getTime()) {
    dueSoon = generation
  }

  const overdue = addDays(end, safeGraceDays)

  return {
    generationDate: toYmd(generation),
    dueSoonDate: toYmd(dueSoon),
    dueDate: toYmd(end),
    overdueDate: toYmd(overdue),
  }
}

interface ComputeConcreteInput {
  issuedAt: string
  dueDate: string
  dueSoonDays: number
  graceDays: number
}

/**
 * Hitos concretos a partir de fechas reales (lote/detalle): el cargo ya tiene
 * issued_at y due_date fijos; sólo derivamos "por vencer" y "moroso".
 */
export function computeConcreteLifecycleMilestones({
  issuedAt,
  dueDate,
  dueSoonDays,
  graceDays,
}: ComputeConcreteInput): ConcreteLifecycleMilestones {
  const generation = resolveAnchor(issuedAt)
  const end = resolveAnchor(dueDate)
  const safeDueSoonDays = clampInt(
    dueSoonDays,
    MIN_DUE_SOON_DAYS,
    MAX_DUE_SOON_DAYS,
    DEFAULT_DUE_SOON_DAYS
  )
  const safeGraceDays = Math.max(0, Math.trunc(Number(graceDays) || 0))

  // dueSoonDate = vencimiento − días de aviso, acotado ≥ fecha de generación
  let dueSoon = addDays(end, -safeDueSoonDays)
  if (dueSoon.getTime() < generation.getTime()) {
    dueSoon = generation
  }
  const overdue = addDays(end, safeGraceDays)

  return {
    generationDate: toYmd(generation),
    dueSoonDate: toYmd(dueSoon),
    dueDate: toYmd(end),
    overdueDate: toYmd(overdue),
  }
}

/**
 * Etapa actual del ciclo según `today`:
 *   - overdue:  hoy > overdueDate
 *   - due_soon: hoy ≥ dueSoonDate (y aún no moroso)
 *   - pending:  antes de "por vencer"
 * MIRRORS la FSM temporal de ChargeStatusService.
 */
export function resolveCurrentStage(
  milestones: ConcreteLifecycleMilestones | LifecycleMilestones,
  today: Date = new Date()
): LifecycleStage {
  const now = toYmd(today)
  if (now > milestones.overdueDate) {
    return 'overdue'
  }
  if (now >= milestones.dueSoonDate) {
    return 'due_soon'
  }
  return 'pending'
}

/**
 * Lee los settings globales de cobranza con sus defaults y acotados.
 * `record` es el mapa por slug que devuelve GET /config/collection-settings.
 */
export function resolveTimingSettings(record: Record<string, unknown> | undefined): TimingSettings {
  return {
    generationDay: clampInt(
      record?.[GENERATION_DAY_SLUG],
      MIN_GENERATION_DAY,
      MAX_GENERATION_DAY,
      DEFAULT_GENERATION_DAY
    ),
    dueSoonDays: clampInt(
      record?.[DUE_SOON_DAYS_SLUG],
      MIN_DUE_SOON_DAYS,
      MAX_DUE_SOON_DAYS,
      DEFAULT_DUE_SOON_DAYS
    ),
    graceDays: clampInt(
      record?.[GRACE_DAYS_SLUG],
      MIN_GRACE_DAYS,
      MAX_GRACE_DAYS,
      DEFAULT_GRACE_DAYS
    ),
  }
}
