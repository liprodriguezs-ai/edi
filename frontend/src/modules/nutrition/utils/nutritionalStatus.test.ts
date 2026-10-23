import {
  cmToMillimetres,
  formatHeightCm,
  formatWeightKg,
  kgToGrams,
  nutritionalStatusTone,
} from '@/modules/nutrition/utils/nutritionalStatus'
import { describe, expect, it } from 'vitest'

describe('nutritionalStatusTone', () => {
  it.each([
    ['severe_malnutrition', 'r'],
    ['moderate_malnutrition', 'r'],
    ['obesity', 'r'],
    ['short_stature', 'r'],
    ['mild_malnutrition', 'y'],
    ['overweight', 'y'],
    ['slightly_short_stature', 'y'],
    ['slightly_tall_stature', 'y'],
    ['tall_stature', 'y'],
    ['normal_weight', 'g'],
    ['normal_stature', 'g'],
    ['no_reference', 'n'],
    [null, 'n'],
  ] as const)('%s → %s', (key, tone) => {
    expect(nutritionalStatusTone(key)).toBe(tone)
  })
})

describe('unit conversions (grams / millimetres on the wire)', () => {
  it('converts kg and cm to integer minor units', () => {
    expect(kgToGrams(12.35)).toBe(12350)
    expect(kgToGrams(9.7)).toBe(9700)
    expect(cmToMillimetres(72.3)).toBe(723)
    expect(cmToMillimetres(81)).toBe(810)
  })

  it('formats grams and millimetres for display', () => {
    expect(formatWeightKg(9700)).toBe('9.70 kg')
    expect(formatHeightCm(723)).toBe('72.3 cm')
    expect(formatWeightKg(null)).toBe('—')
    expect(formatHeightCm(null)).toBe('—')
  })
})
