import type { IMoney } from '@/shared/utils/money'

/**
 * Tablero: Cobranza (aging / morosidad)
 * Endpoint: GET /collections/aging
 * Los montos llegan serializados como Money ({amount, currency, formatted}).
 */

export interface IAgingBuckets {
  /** Cargos aún no vencidos */
  current: IMoney
  /** Vencidos 1-30 días */
  d1_30: IMoney
  /** Vencidos 31-60 días */
  d31_60: IMoney
  /** Vencidos 61-90 días */
  d61_90: IMoney
  /** Vencidos más de 90 días */
  d90_plus: IMoney
}

export interface IAgingByStatus {
  /** Clave estable del estado, ej. "overdue" */
  status_key: string
  /** Nombre legible del estado en español */
  label: string
  /** Cantidad de cargos en este estado */
  count: number
  /** Suma de montos pendientes */
  amount: IMoney
}

export interface ITopDebtor {
  /** Identificador legible de la unidad */
  unit_label: string
  /** Monto total pendiente de la unidad */
  outstanding: IMoney
  /** Número de cargos pendientes */
  charge_count: number
}

export interface IAgingTotals {
  /** Total de cartera pendiente */
  total_outstanding: IMoney
  /** Número de unidades con saldo pendiente */
  units_with_debt: number
  /** Antigüedad promedio en días (entero) */
  avg_days_overdue: number
  /** Porcentaje de cobro (0-100 entero) */
  collection_rate_pct: number
}

/** Payload completo del endpoint /collections/aging */
export interface ICobranzaPayload {
  totals: IAgingTotals
  buckets: IAgingBuckets
  by_status: IAgingByStatus[]
  top_debtors: ITopDebtor[]
}

/**
 * Row adapter for ResponsiveDataTable — extends ITopDebtor with a stable id
 * and the index signature required by IEntity.
 */
export interface ITopDebtorRow {
  id: string
  unit_label: string
  outstanding: IMoney
  charge_count: number
  [key: string]: unknown
}
