import { getActiveCurrency, setActiveCurrency } from '@/shared/utils/currency'
import {
  displayMoney,
  formatMinorUnits,
  minorUnitsToPesos,
  moneyToMinorUnits,
  pesosToMinorUnits,
} from '@/shared/utils/money'
import { afterEach, describe, expect, it } from 'vitest'

describe('money minor-unit helpers', () => {
  afterEach(() => {
    setActiveCurrency(null) // reset to MXN so state doesn't leak across tests in this file
  })

  it('converts pesos to minor units (rounding)', () => {
    expect(pesosToMinorUnits(1500)).toBe(150000)
    expect(pesosToMinorUnits(1500.005)).toBe(150001) // half-up rounding
  })

  it('converts minor units back to pesos', () => {
    expect(minorUnitsToPesos(150000)).toBe(1500)
  })

  it('reads minor units from a serialized money cast, a raw int, or a string', () => {
    expect(moneyToMinorUnits({ amount: '70000', currency: 'MXN', formatted: '$700.00' })).toBe(
      70000
    )
    expect(moneyToMinorUnits(70000)).toBe(70000)
    expect(moneyToMinorUnits('70000')).toBe(70000)
    expect(moneyToMinorUnits(null)).toBe(0)
    expect(moneyToMinorUnits(undefined)).toBe(0)
  })

  it('formats minor units as MXN currency', () => {
    // toLocaleString uses a non-breaking space; assert on the numeric parts.
    const formatted = formatMinorUnits(150000)
    expect(formatted).toContain('1,500.00')
    expect(formatMinorUnits(null)).toContain('0.00')
  })

  it('prefers the serialized `formatted` string when present', () => {
    expect(displayMoney({ amount: '70000', currency: 'MXN', formatted: '$700.00' })).toBe('$700.00')
    expect(displayMoney(70000)).toContain('700.00')
  })

  it('formats with the active currency instead of a hardcoded MXN', () => {
    setActiveCurrency('USD')
    expect(getActiveCurrency()).toBe('USD')
    expect(formatMinorUnits(150000)).toContain('USD')
  })

  it('resets to MXN when the active currency is cleared', () => {
    setActiveCurrency('USD')
    setActiveCurrency(null)
    expect(getActiveCurrency()).toBe('MXN')
    expect(formatMinorUnits(150000)).toContain('1,500.00')
  })
})
