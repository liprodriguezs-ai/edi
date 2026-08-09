import type { ICharge } from '@/modules/charge/schema'
import { moneyToMinorUnits } from '@/shared/utils/money'

export interface ChargeRow {
  charge: ICharge
  expected: number
  paid: number
  outstanding: number
}

/** Deriva las filas (esperado/pagado/faltante) de los cargos no cancelados de un lote. */
export function toChargeRows(charges: ICharge[]): ChargeRow[] {
  // Defensa: ante una respuesta malformada o una entrada de caché con otra
  // shape, devolvemos vacío en lugar de tumbar la página con `.filter is not a
  // function`.
  if (!Array.isArray(charges)) {
    return []
  }
  return charges
    .filter((charge) => !charge.is_cancelled)
    .map((charge) => {
      const expected = moneyToMinorUnits(charge.expected_amount)
      const discount = moneyToMinorUnits(charge.discount_amount ?? 0)
      const paid = moneyToMinorUnits(charge.paid_amount)
      return { charge, expected, paid, outstanding: Math.max(0, expected - discount - paid) }
    })
}
