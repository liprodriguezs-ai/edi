import {
  birthRecordFormSchema,
  booleanToTriState,
  triStateToBoolean,
} from '@/modules/child/schemas/birthRecordSchema'
import { describe, expect, it } from 'vitest'

const CHILD_ID = '11111111-1111-1111-1111-111111111111'

describe('birthRecordFormSchema', () => {
  it('accepts an all-null (fully unknown) payload — REQ-CH-007 partial capture', () => {
    const result = birthRecordFormSchema.safeParse({
      child_id: CHILD_ID,
      gestational_age_weeks: null,
      birth_weight_grams: null,
      birth_length_mm: null,
      prenatal_visit_count: null,
      had_pregnancy_complication: null,
      had_delivery_complication: null,
      nicu_days: null,
      mother_age_at_birth_years: null,
      early_breastfeeding: null,
      breastfeeding_months: null,
    })
    expect(result.success).toBe(true)
  })

  it('accepts a partial capture with only birth_weight_grams set', () => {
    const result = birthRecordFormSchema.safeParse({
      child_id: CHILD_ID,
      birth_weight_grams: 3100,
    })
    expect(result.success).toBe(true)
  })

  it.each([
    ['gestational_age_weeks', 19],
    ['gestational_age_weeks', 45],
    ['birth_weight_grams', 399],
    ['birth_weight_grams', 7001],
    ['birth_length_mm', 199],
    ['birth_length_mm', 651],
    ['prenatal_visit_count', -1],
    ['prenatal_visit_count', 41],
    ['nicu_days', -1],
    ['nicu_days', 366],
    ['mother_age_at_birth_years', 9],
    ['mother_age_at_birth_years', 61],
    ['breastfeeding_months', -1],
    ['breastfeeding_months', 72],
  ])('rejects %s out of range (%d)', (field, value) => {
    const result = birthRecordFormSchema.safeParse({ child_id: CHILD_ID, [field]: value })
    expect(result.success).toBe(false)
  })

  it('rejects a decimal value (integers only, minor units)', () => {
    const result = birthRecordFormSchema.safeParse({ child_id: CHILD_ID, birth_weight_grams: 3100.5 })
    expect(result.success).toBe(false)
  })
})

describe('tri-state boolean mapping (REQ-CH-007 / REQ-XC-006)', () => {
  it('maps true -> yes, false -> no, null/undefined -> unknown', () => {
    expect(booleanToTriState(true)).toBe('yes')
    expect(booleanToTriState(false)).toBe('no')
    expect(booleanToTriState(null)).toBe('unknown')
    expect(booleanToTriState(undefined)).toBe('unknown')
  })

  it('maps yes -> true, no -> false, unknown -> null (never a defaulted false)', () => {
    expect(triStateToBoolean('yes')).toBe(true)
    expect(triStateToBoolean('no')).toBe(false)
    expect(triStateToBoolean('unknown')).toBeNull()
  })
})
