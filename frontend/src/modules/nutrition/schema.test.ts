import { nutritionalAssessmentFormSchema, toNutritionalAssessmentPayload } from '@/modules/nutrition/schema'
import { describe, expect, it } from 'vitest'

describe('nutritionalAssessmentFormSchema', () => {
  it('requires at least the weight or the height', () => {
    const result = nutritionalAssessmentFormSchema.safeParse({ measured_on: '2025-01-10', weight_kg: '', height_cm: '' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues[0].message).toBe('Captura al menos el peso o la talla')
    }
  })

  it('rejects out-of-range measures', () => {
    expect(nutritionalAssessmentFormSchema.safeParse({ measured_on: '2025-01-10', weight_kg: '0.4', height_cm: '' }).success).toBe(false)
    expect(nutritionalAssessmentFormSchema.safeParse({ measured_on: '2025-01-10', weight_kg: '', height_cm: '141' }).success).toBe(false)
  })

  it('builds the payload in grams and millimetres', () => {
    const values = nutritionalAssessmentFormSchema.parse({ measured_on: '2025-01-10', weight_kg: '9.7', height_cm: '72.3', notes: '' })
    expect(toNutritionalAssessmentPayload('child-1', values)).toEqual({
      child_id: 'child-1',
      measured_on: '2025-01-10',
      weight_grams: 9700,
      height_mm: 723,
      notes: null,
    })
  })

  it('sends null for an omitted measure', () => {
    const values = nutritionalAssessmentFormSchema.parse({ measured_on: '2025-01-10', weight_kg: '', height_cm: '81' })
    expect(toNutritionalAssessmentPayload('child-1', values).weight_grams).toBeNull()
  })
})
