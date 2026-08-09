import { describe, expect, it } from 'vitest'
import {
  chargeConceptFormSchema,
  type ChargeConceptFormValues,
} from '@/modules/chargeConcept/schema'

/** Valores base válidos (identidad mínima del concepto). */
function baseValues(overrides: Partial<ChargeConceptFormValues> = {}): ChargeConceptFormValues {
  return {
    code: 'MANT_MENSUAL',
    name: 'Mantenimiento mensual',
    description: '',
    charge_concept_category_id: 'cat-1',
    is_active: true,
    ...overrides,
  }
}

function issueOn(values: ChargeConceptFormValues, path: string): string | undefined {
  const result = chargeConceptFormSchema.safeParse(values)
  if (result.success) return undefined
  return result.error.issues.find((i) => i.path[0] === path)?.message
}

describe('chargeConceptFormSchema — identidad (slim tras split policy)', () => {
  it('acepta una identidad válida', () => {
    expect(chargeConceptFormSchema.safeParse(baseValues()).success).toBe(true)
  })

  it('rechaza clave vacía', () => {
    expect(issueOn(baseValues({ code: '' }), 'code')).toBe('La clave es requerida')
  })

  it('rechaza clave con minúsculas o espacios', () => {
    expect(issueOn(baseValues({ code: 'mant mensual' }), 'code'))
      .toBe('Solo mayúsculas, números y guión bajo')
  })

  it('rechaza nombre vacío', () => {
    expect(issueOn(baseValues({ name: '' }), 'name')).toBe('El nombre es requerido')
  })

  it('rechaza categoría vacía', () => {
    expect(issueOn(baseValues({ charge_concept_category_id: '' }), 'charge_concept_category_id'))
      .toBe('La categoría es requerida')
  })

  it('no admite campos de monto/periodicidad (ya no son parte del concepto)', () => {
    const result = chargeConceptFormSchema.safeParse(baseValues())
    expect(result.success).toBe(true)
    if (result.success) {
      expect('amount_mode_id' in result.data).toBe(false)
      expect('periodicity_id' in result.data).toBe(false)
      expect('valid_from' in result.data).toBe(false)
    }
  })
})
