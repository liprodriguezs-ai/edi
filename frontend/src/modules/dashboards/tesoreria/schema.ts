import type { IMoney } from '@/shared/utils/money'

/**
 * Tablero: Tesorería (flujo de caja)
 * Endpoint: GET /treasury/cashflow-series?months=N
 * Los montos llegan serializados como Money ({amount, currency, formatted}).
 */

export interface ICashflowAccount {
  id: string
  name: string
  /** Saldo actual de la cuenta */
  current_balance: IMoney
}

export interface ICashflowBucket {
  /** Etiqueta del mes, ej. "Jun 2026" */
  label: string
  /** Año del bucket */
  year: number
  /** Mes del bucket (1-12) */
  month: number
  /** Entradas (ingresos) del mes */
  inflow: IMoney
  /** Salidas (egresos) del mes */
  outflow: IMoney
  /** Saldo acumulado neto del periodo */
  running_balance: IMoney
}

export interface ICashflowTotals {
  /** Saldo total actual de todas las cuentas activas */
  total_balance: IMoney
  /** Número de cuentas bancarias activas */
  account_count: number
  /** Entradas del mes en curso */
  current_month_inflow: IMoney
  /** Salidas del mes en curso */
  current_month_outflow: IMoney
}

/** Payload completo del endpoint /treasury/cashflow-series */
export interface ITesoreriaPayload {
  accounts: ICashflowAccount[]
  series: ICashflowBucket[]
  totals: ICashflowTotals
}
