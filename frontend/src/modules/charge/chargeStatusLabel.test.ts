import { describe, expect, it } from 'vitest'
import { CHARGE_STATUS, chargeStatusLabel, type ICharge } from '@/modules/charge/schema'

/** Cargo mínimo válido para el helper (solo importan estado, pago y nombre). */
function makeCharge(overrides: Partial<ICharge>): ICharge {
  return {
    id: 'charge-1',
    unit_id: 'unit-1',
    expected_amount: 100000,
    paid_amount: 0,
    charge_status_id: CHARGE_STATUS.PENDING,
    charge_status: { id: CHARGE_STATUS.PENDING, name: 'Pendiente' },
    ...overrides,
  } as ICharge
}

describe('chargeStatusLabel', () => {
  it('agrega " · parcial" cuando está vencido con pago parcial', () => {
    const charge = makeCharge({
      charge_status_id: CHARGE_STATUS.OVERDUE,
      charge_status: { id: CHARGE_STATUS.OVERDUE, name: 'Vencido' },
      paid_amount: 40000,
    })
    expect(chargeStatusLabel(charge)).toBe('Vencido · parcial')
  })

  it('no agrega la pista si está vencido pero sin pago', () => {
    const charge = makeCharge({
      charge_status_id: CHARGE_STATUS.OVERDUE,
      charge_status: { id: CHARGE_STATUS.OVERDUE, name: 'Vencido' },
      paid_amount: 0,
    })
    expect(chargeStatusLabel(charge)).toBe('Vencido')
  })

  it('no agrega la pista para estados no vencidos aunque tengan pago', () => {
    const charge = makeCharge({
      charge_status_id: CHARGE_STATUS.PARTIAL,
      charge_status: { id: CHARGE_STATUS.PARTIAL, name: 'Parcial' },
      paid_amount: 40000,
    })
    expect(chargeStatusLabel(charge)).toBe('Parcial')
  })

  it('usa el fallback cuando no hay charge_status', () => {
    const charge = makeCharge({ charge_status: null })
    expect(chargeStatusLabel(charge)).toBe('—')
  })
})
