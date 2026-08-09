/**
 * Dinero EDI: el backend almacena bigint en MINOR UNITS (centavos) y
 * serializa los casts como { amount: "70000", currency: "MXN", formatted: "$700.00" }.
 * Los formularios ENVÍAN enteros en minor units.
 */
import { getActiveCurrency } from '@/shared/utils/currency'

export interface IMoney {
  amount: string
  currency: string
  formatted: string
}

/** Pesos capturados en un input → minor units para el backend */
export function pesosToMinorUnits(pesos: number): number {
  return Math.round(pesos * 100)
}

/** Minor units → pesos (para precargar inputs en edición) */
export function minorUnitsToPesos(minor: number): number {
  return minor / 100
}

/** Formatea minor units como moneda MXN ($1,500.00) */
export function formatMinorUnits(minor: number | string | null | undefined): string {
  const value = typeof minor === 'string' ? parseInt(minor, 10) : (minor ?? 0)
  const safe = Number.isFinite(value) ? value : 0
  return (safe / 100).toLocaleString('es-MX', {
    style: 'currency',
    currency: getActiveCurrency(),
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

/** Lee el monto en minor units de un campo money serializado (o entero crudo) */
export function moneyToMinorUnits(money: IMoney | number | string | null | undefined): number {
  if (money == null) return 0
  if (typeof money === 'number') return money
  if (typeof money === 'string') return parseInt(money, 10) || 0
  return parseInt(money.amount, 10) || 0
}

/** Muestra un campo money serializado; tolera enteros crudos */
export function displayMoney(money: IMoney | number | string | null | undefined): string {
  if (money != null && typeof money === 'object' && 'formatted' in money) {
    return money.formatted
  }
  return formatMinorUnits(money as number | string | null | undefined)
}
