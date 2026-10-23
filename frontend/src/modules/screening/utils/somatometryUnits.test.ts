import { describe, expect, it } from 'vitest'
import { fromStoredUnit, toStoredUnit } from './somatometryUnits'

describe('somatometry units', () => {
  it('shows mm as cm and g as kg', () => {
    expect(fromStoredUnit(705, 'cm')).toBe('70.5')
    expect(fromStoredUnit(440, 'cm')).toBe('44')
    expect(fromStoredUnit(8200, 'kg')).toBe('8.2')
    expect(fromStoredUnit(null, 'kg')).toBe('')
  })

  it('stores cm as whole mm and kg as whole g', () => {
    expect(toStoredUnit('70.5', 'cm')).toBe(705)
    expect(toStoredUnit('70,5', 'cm')).toBe(705)
    expect(toStoredUnit('8.25', 'kg')).toBe(8250)
    expect(toStoredUnit('', 'cm')).toBeNull()
    expect(toStoredUnit('abc', 'kg')).toBeNull()
  })
})
