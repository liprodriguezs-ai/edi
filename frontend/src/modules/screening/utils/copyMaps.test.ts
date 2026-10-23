import { findingCopy } from '@/modules/screening/utils/findingCopy'
import { lockedReasonCopy } from '@/modules/screening/utils/lockedReasonCopy'
import { describe, expect, it } from 'vitest'

describe('findingCopy', () => {
  it('resolves a known finding key to Spanish copy', () => {
    expect(findingCopy('auto_answer_overrode_manual', null)).toContain('sustituida')
  })

  it('falls back to a generic sentence for an unknown key', () => {
    expect(findingCopy(undefined, null)).toBe('Hallazgo del motor de calificación.')
    expect(findingCopy('some_future_key', null)).toBe('Hallazgo del motor de calificación.')
  })

  it('interpolates context when available', () => {
    const copy = findingCopy('unknown_perinatal_input', { source_field: 'birth_weight_grams' })
    expect(copy).toBe('El dato de peso al nacer no está disponible o no es plausible; no se tomó en cuenta en el cálculo.')
  })

  it('never shows a raw field identifier to the evaluator', () => {
    const copy = findingCopy('unknown_perinatal_input', { source_field: 'head_circumference_mm' })
    expect(copy).toContain('perímetro cefálico')
    expect(copy).not.toContain('head_circumference_mm')
  })
})

describe('lockedReasonCopy', () => {
  it('renders a head-circumference lock reason with value and unit', () => {
    const copy = lockedReasonCopy({
      auto_derivation_key: 'head_circumference',
      source_field: 'head_circumference_mm',
      value: 360,
    })
    expect(copy).toContain('360')
    expect(copy).toContain('perímetro cefálico')
  })

  it('falls back to a generic sentence when no reason is given', () => {
    expect(lockedReasonCopy(null)).toBe('Esta respuesta se deriva automáticamente.')
  })
})
