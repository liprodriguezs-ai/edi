import { describe, expect, it } from 'vitest'
import type { IChargeBatch } from '@/modules/chargeBatch/schema'
import { PERIODICITY } from '@/modules/chargeConcept/schema'
import { periodLabel } from '@/modules/chargeBatch/periodLabel'

/** Lote mínimo: sólo los campos que lee `periodLabel`. */
function batch(overrides: Partial<IChargeBatch>): IChargeBatch {
  return {
    id: 'batch-1',
    charge_policy_id: 'policy-1',
    title: 'Cuota',
    year: 2026,
    month: null,
    cycle: null,
    target_type: 'all',
    issued_at: '2026-07-01',
    due_date: '2026-07-01',
    charge_batch_status_id: 'status-1',
    ...overrides,
  } as IChargeBatch
}

describe('periodLabel', () => {
  it('anclado (period_start presente) → rango real con EN DASH', () => {
    expect(
      periodLabel(
        batch({ period_start: '2026-07-15', due_date: '2026-08-14', month: 7 }),
        PERIODICITY.MONTHLY
      )
    ).toBe('15 jul – 14 ago 2026')
  })

  it('legacy (period_start = null) conserva la reconstrucción por año/mes', () => {
    expect(
      periodLabel(batch({ period_start: null, year: 2026, month: 7 }), PERIODICITY.MONTHLY)
    ).toBe('Julio 2026')
  })

  it('mensual → "Mes Año"', () => {
    expect(periodLabel(batch({ month: 7 }), PERIODICITY.MONTHLY)).toBe('Julio 2026')
  })

  it('anual (sin mes) → sólo el año', () => {
    expect(periodLabel(batch({ month: null }), PERIODICITY.YEARLY)).toBe('2026')
  })

  it('sin periodicidad conocida conserva la etiqueta de mes', () => {
    expect(periodLabel(batch({ month: 7 }))).toBe('Julio 2026')
  })

  it('cada 2 días → rango de la ventana con EN DASH', () => {
    expect(periodLabel(batch({ month: 7, cycle: 24 }), PERIODICITY.EVERY_TWO_DAYS)).toBe(
      '24/07–25/07/2026'
    )
  })

  it('cada 2 días → la ventana que cruza el mes muestra ambos meses', () => {
    // year/month/cycle identifican la ventana por su día de INICIO (30/06).
    expect(periodLabel(batch({ month: 6, cycle: 30 }), PERIODICITY.EVERY_TWO_DAYS)).toBe(
      '30/06–01/07/2026'
    )
  })

  it('cada 2 días → la ventana que cruza el año muestra el año del fin', () => {
    expect(
      periodLabel(batch({ year: 2026, month: 12, cycle: 31 }), PERIODICITY.EVERY_TWO_DAYS)
    ).toBe('31/12–01/01/2027')
  })

  it('semanal NO se reinterpreta como ventana pese a traer cycle (semana ISO)', () => {
    expect(periodLabel(batch({ month: 6, cycle: 26 }), PERIODICITY.WEEKLY)).toBe('Junio 2026')
  })

  it('quincenal NO se reinterpreta como ventana pese a traer cycle (1|2)', () => {
    expect(periodLabel(batch({ month: 6, cycle: 2 }), PERIODICITY.BIWEEKLY)).toBe('Junio 2026')
  })

  it('eventual → "Eventual" cuando no hay mes ni año', () => {
    expect(periodLabel(batch({ year: 0, month: null }), PERIODICITY.EVENTUAL)).toBe('Eventual')
  })
})
