import { describe, expect, it } from 'vitest'
import { PERIODICITY } from '@/modules/chargeConcept/schema'
import {
  anchoredPeriodBounds,
  computeConcreteLifecycleMilestones,
  computeLifecycleMilestones,
  resolveCurrentStage,
  resolveTimingSettings,
} from '@/shared/utils/chargeLifecycle'

/** Helper: fecha local (sin desfase UTC). */
function ymd(y: number, m: number, d: number): Date {
  return new Date(y, m - 1, d)
}

describe('anchoredPeriodBounds', () => {
  it('monthly → arranca en periodStart, termina un mes después menos un día', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.MONTHLY, ymd(2026, 6, 15))
    expect(start).toEqual(ymd(2026, 6, 15))
    expect(end).toEqual(ymd(2026, 7, 14))
  })

  it('monthly → overflow-safe: 31-ene no se corre a marzo', () => {
    const { end } = anchoredPeriodBounds(PERIODICITY.MONTHLY, ymd(2026, 1, 31))
    expect(end).toEqual(ymd(2026, 2, 27))
  })

  it('monthly → overflow-safe en año bisiesto', () => {
    const { end } = anchoredPeriodBounds(PERIODICITY.MONTHLY, ymd(2024, 1, 31))
    expect(end).toEqual(ymd(2024, 2, 28))
  })

  it('bimonthly → arranca en periodStart, termina dos meses después menos un día', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.BIMONTHLY, ymd(2026, 6, 15))
    expect(start).toEqual(ymd(2026, 6, 15))
    expect(end).toEqual(ymd(2026, 8, 14))
  })

  it('bimonthly → overflow-safe cruzando fin de año', () => {
    const { end } = anchoredPeriodBounds(PERIODICITY.BIMONTHLY, ymd(2026, 12, 31))
    expect(end).toEqual(ymd(2027, 2, 27))
  })

  it('yearly → arranca en periodStart, termina un año después menos un día', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.YEARLY, ymd(2026, 6, 15))
    expect(start).toEqual(ymd(2026, 6, 15))
    expect(end).toEqual(ymd(2027, 6, 14))
  })

  it('yearly → el ancla bisiesta (29-feb) deriva a 27-feb, próximo arranque 28-feb', () => {
    const { end } = anchoredPeriodBounds(PERIODICITY.YEARLY, ymd(2024, 2, 29))
    expect(end).toEqual(ymd(2025, 2, 27))
  })

  it('weekly → 7 días desde periodStart', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.WEEKLY, ymd(2026, 6, 15))
    expect(start).toEqual(ymd(2026, 6, 15))
    expect(end).toEqual(ymd(2026, 6, 21))
  })

  it('invariante de cadena: el próximo período arranca justo donde termina este', () => {
    for (const periodicityId of [
      PERIODICITY.MONTHLY,
      PERIODICITY.BIMONTHLY,
      PERIODICITY.YEARLY,
      PERIODICITY.WEEKLY,
      PERIODICITY.BIWEEKLY,
      PERIODICITY.EVERY_TWO_DAYS,
    ]) {
      const first = anchoredPeriodBounds(periodicityId, ymd(2026, 1, 15))
      const nextStart = new Date(first.end)
      nextStart.setDate(nextStart.getDate() + 1)
      const second = anchoredPeriodBounds(periodicityId, nextStart)
      expect(second.start).toEqual(nextStart)
    }
  })

  it('biweekly → 15 días corridos desde el ancla', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.BIWEEKLY, ymd(2026, 6, 1))
    expect(start).toEqual(ymd(2026, 6, 1))
    expect(end).toEqual(ymd(2026, 6, 15))
  })

  it('biweekly → un ancla a mitad de mes NO se retrae al día 16', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.BIWEEKLY, ymd(2026, 6, 20))
    expect(start).toEqual(ymd(2026, 6, 20))
    expect(end).toEqual(ymd(2026, 7, 4))
  })

  it('every_two_days → el período arranca EXACTAMENTE en el ancla, nunca un día antes', () => {
    // Regresión: la rejilla de epoch abría este período el 30/06, un día antes de la
    // vigencia. Ahora la fecha de inicio es la fecha de inicio.
    const { start, end } = anchoredPeriodBounds(PERIODICITY.EVERY_TWO_DAYS, ymd(2026, 7, 1))
    expect(start).toEqual(ymd(2026, 7, 1))
    expect(end).toEqual(ymd(2026, 7, 2))
  })

  it('every_two_days → cualquier ancla abre su propio período de 2 días', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.EVERY_TWO_DAYS, ymd(2026, 7, 24))
    expect(start).toEqual(ymd(2026, 7, 24))
    expect(end).toEqual(ymd(2026, 7, 25))
  })

  it('every_two_days → períodos consecutivos no se solapan ni dejan huecos', () => {
    const current = anchoredPeriodBounds(PERIODICITY.EVERY_TWO_DAYS, ymd(2026, 7, 24))
    const next = anchoredPeriodBounds(PERIODICITY.EVERY_TWO_DAYS, ymd(2026, 7, 26))
    expect(next.start).toEqual(ymd(2026, 7, 26))
    expect(next.start.getTime() - current.end.getTime()).toBe(86_400_000)
  })

  it('eventual / desconocida → fallback mensual', () => {
    const { start, end } = anchoredPeriodBounds(PERIODICITY.EVENTUAL, ymd(2026, 6, 15))
    expect(start).toEqual(ymd(2026, 6, 15))
    expect(end).toEqual(ymd(2026, 7, 14))
  })
})

describe('computeLifecycleMilestones', () => {
  it('mensual anclado: anchorDate ES el arranque del período (no un punto dentro de él)', () => {
    const m = computeLifecycleMilestones({
      periodicityId: PERIODICITY.MONTHLY,
      generationDay: 1,
      dueSoonDays: 7,
      graceDays: 5,
      anchorDate: '2026-06-15',
    })
    expect(m.generationDate).toBe('2026-06-15') // arranque + (1-1)
    expect(m.dueDate).toBe('2026-07-14') // arranque + 1 mes − 1 día
    expect(m.dueSoonDate).toBe('2026-07-07') // fin − 7
    expect(m.overdueDate).toBe('2026-07-19') // fin + 5 días de gracia
  })

  it('día de generación posterior se acota ≤ fin del período', () => {
    // generationDay 28 en weekly (período de 7 días) → cae al fin del período
    const m = computeLifecycleMilestones({
      periodicityId: PERIODICITY.WEEKLY,
      generationDay: 28,
      dueSoonDays: 2,
      graceDays: 0,
      anchorDate: '2026-06-24',
    })
    expect(m.dueDate).toBe('2026-06-30')
    expect(m.generationDate).toBe('2026-06-30') // acotado al fin
  })

  it('dueSoonDate se acota ≥ generationDate cuando due_soon_days es grande', () => {
    // due_soon_days 60 en un mes → fin−60 quedaría antes del nacimiento → clamp
    const m = computeLifecycleMilestones({
      periodicityId: PERIODICITY.MONTHLY,
      generationDay: 5,
      dueSoonDays: 60,
      graceDays: 0,
      anchorDate: '2026-06-15',
    })
    expect(m.generationDate).toBe('2026-06-19')
    expect(m.dueSoonDate).toBe('2026-06-19') // acotado al nacimiento, no antes
  })

  it('cada 2 días → hitos dentro del período, no del mes', () => {
    const m = computeLifecycleMilestones({
      periodicityId: PERIODICITY.EVERY_TWO_DAYS,
      generationDay: 1,
      dueSoonDays: 7,
      graceDays: 1,
      anchorDate: '2026-07-01',
    })
    expect(m.generationDate).toBe('2026-07-01') // el ancla ES el inicio del período
    expect(m.dueDate).toBe('2026-07-02') // fin del período, NO fin de mes
    expect(m.dueSoonDate).toBe('2026-07-01') // fin − 7 se acota al nacimiento
    expect(m.overdueDate).toBe('2026-07-03') // fin + 1 día de gracia
  })

  it('grace_days 0 → moroso = mismo día del vencimiento', () => {
    const m = computeLifecycleMilestones({
      periodicityId: PERIODICITY.MONTHLY,
      generationDay: 1,
      dueSoonDays: 7,
      graceDays: 0,
      anchorDate: '2026-02-10',
    })
    expect(m.dueDate).toBe('2026-03-09')
    expect(m.overdueDate).toBe('2026-03-09')
  })
})

describe('computeConcreteLifecycleMilestones', () => {
  it('deriva nace / por-vencer / moroso desde fechas reales', () => {
    const m = computeConcreteLifecycleMilestones({
      issuedAt: '2026-06-01',
      dueDate: '2026-06-30',
      dueSoonDays: 7,
      graceDays: 5,
    })
    expect(m.generationDate).toBe('2026-06-01') // "Nace" = issued_at
    expect(m.dueDate).toBe('2026-06-30')
    expect(m.dueSoonDate).toBe('2026-06-23')
    expect(m.overdueDate).toBe('2026-07-05')
  })

  it('acota por-vencer ≥ fecha de generación cuando dueSoonDays es grande', () => {
    const m = computeConcreteLifecycleMilestones({
      issuedAt: '2026-06-20',
      dueDate: '2026-06-30',
      dueSoonDays: 60,
      graceDays: 0,
    })
    expect(m.generationDate).toBe('2026-06-20')
    expect(m.dueSoonDate).toBe('2026-06-20') // acotado al nacimiento
  })
})

describe('resolveCurrentStage', () => {
  const milestones = {
    generationDate: '2026-06-01',
    dueSoonDate: '2026-06-23',
    dueDate: '2026-06-30',
    overdueDate: '2026-07-05',
  }

  it('pending antes de "por vencer"', () => {
    expect(resolveCurrentStage(milestones, ymd(2026, 6, 10))).toBe('pending')
  })

  it('due_soon entre "por vencer" y moroso', () => {
    expect(resolveCurrentStage(milestones, ymd(2026, 6, 25))).toBe('due_soon')
    expect(resolveCurrentStage(milestones, ymd(2026, 7, 5))).toBe('due_soon')
  })

  it('overdue después de la fecha moroso', () => {
    expect(resolveCurrentStage(milestones, ymd(2026, 7, 6))).toBe('overdue')
  })
})

describe('resolveTimingSettings', () => {
  it('usa los defaults cuando el record está vacío', () => {
    expect(resolveTimingSettings({})).toEqual({ generationDay: 1, dueSoonDays: 7, graceDays: 5 })
  })

  it('usa los defaults cuando el record es undefined', () => {
    expect(resolveTimingSettings(undefined)).toEqual({
      generationDay: 1,
      dueSoonDays: 7,
      graceDays: 5,
    })
  })

  it('lee y acota los valores del record', () => {
    expect(
      resolveTimingSettings({
        'collections.charge_generation_day': 15,
        'collections.due_soon_days': 10,
        'collections.grace_days': 3,
      })
    ).toEqual({ generationDay: 15, dueSoonDays: 10, graceDays: 3 })
  })

  it('usa el default de gracia (5) cuando el record no lo trae', () => {
    expect(resolveTimingSettings({ 'collections.due_soon_days': 10 }).graceDays).toBe(5)
  })

  it('acota los días de gracia a [0, 60]', () => {
    expect(resolveTimingSettings({ 'collections.grace_days': 99 }).graceDays).toBe(60)
    expect(resolveTimingSettings({ 'collections.grace_days': -4 }).graceDays).toBe(0)
  })

  it('acota el día de generación a [1, 28]', () => {
    expect(resolveTimingSettings({ 'collections.charge_generation_day': 99 }).generationDay).toBe(
      28
    )
    expect(resolveTimingSettings({ 'collections.charge_generation_day': 0 }).generationDay).toBe(1)
  })
})
