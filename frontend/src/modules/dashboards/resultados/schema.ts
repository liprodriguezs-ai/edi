import type { IMoney } from '@/shared/utils/money'

/**
 * Tablero: Estado de Resultados (P&L)
 * Endpoint: GET /treasury/income-expense-series?months=N
 * Los montos llegan serializados como Money ({amount, currency, formatted}).
 */

export interface IResultadosBucket {
  /** Etiqueta del mes, ej. "Jun 2026" */
  label: string
  /** Año del bucket */
  year: number
  /** Mes del bucket (1-12) */
  month: number
  /** Ingresos del mes */
  income: IMoney
  /** Egresos del mes */
  expense: IMoney
  /** Neto (income - expense) */
  net: IMoney
}

export interface IResultadosTotals {
  /** Total de ingresos en el periodo */
  income: IMoney
  /** Total de egresos en el periodo */
  expense: IMoney
  /** Neto total del periodo */
  net: IMoney
  /** Margen neto en porcentaje entero (0–100), 0 si income=0 */
  margin_pct: number
}

/** Payload completo del endpoint /treasury/income-expense-series */
export interface IResultadosPayload {
  series: IResultadosBucket[]
  totals: IResultadosTotals
}
