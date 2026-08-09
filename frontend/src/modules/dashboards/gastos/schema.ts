import type { IMoney } from '@/shared/utils/money'

/**
 * Tablero: Gastos (expenses breakdown)
 * Endpoint: GET /treasury/expenses-breakdown?months=N
 * Los montos llegan serializados como Money ({amount, currency, formatted}).
 */

export interface IGastoCategoryItem {
  /** ID de la categoría */
  expense_category_id: string
  /** Nombre legible de la categoría */
  label: string
  /** Total de egresos en la categoría */
  amount: IMoney
  /** Porcentaje del total (0-100, entero) */
  pct: number
}

export interface IGastoProviderItem {
  /** ID del proveedor */
  provider_id: string
  /** Nombre del proveedor */
  label: string
  /** Total de egresos vinculados a este proveedor */
  amount: IMoney
}

export interface IGastoMonthlyBucket {
  /** Etiqueta del mes, ej. "Jun 2026" */
  label: string
  /** Año del bucket */
  year: number
  /** Mes del bucket (1-12) */
  month: number
  /** Total de egresos en el mes */
  amount: IMoney
}

export interface IGastoTotals {
  /** Total de egresos en el periodo */
  total: IMoney
  /** Egresos recurrentes (via proveedor con is_recurring) */
  recurring: IMoney
  /** Egresos no recurrentes */
  one_off: IMoney
  /** Porcentaje recurrente (0-100, entero) */
  recurring_pct: number
  /** Número de proveedores únicos con egresos en el periodo */
  provider_count: number
}

/** Payload completo del endpoint /treasury/expenses-breakdown */
export interface IGastosPayload {
  totals: IGastoTotals
  by_category: IGastoCategoryItem[]
  by_provider: IGastoProviderItem[]
  monthly: IGastoMonthlyBucket[]
}
