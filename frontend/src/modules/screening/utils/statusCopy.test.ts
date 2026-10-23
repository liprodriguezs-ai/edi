import { describe, expect, it } from 'vitest'
import { screeningStatusLabel, screeningStepIndex } from '@/modules/screening/utils/statusCopy'

describe('screeningStepIndex', () => {
  it('places a draft on "Capturar" while capturing and on "Calificar" while reviewing', () => {
    expect(screeningStepIndex('draft', 'capture')).toBe(0)
    expect(screeningStepIndex('draft', 'review')).toBe(1)
  })

  it('places a scored screening on "Cerrar y agendar cita"', () => {
    expect(screeningStepIndex('scored')).toBe(2)
  })

  it('marks every step done once the screening is closed', () => {
    expect(screeningStepIndex('completed')).toBe(3)
  })

  it('has no step for an annulled screening', () => {
    expect(screeningStepIndex('invalidated')).toBeNull()
  })
})

describe('screeningStatusLabel', () => {
  it('uses the same words as the actions that reach each state', () => {
    expect(screeningStatusLabel('draft')).toBe('Borrador')
    expect(screeningStatusLabel('scored')).toBe('Calificada')
    expect(screeningStatusLabel('completed')).toBe('Cerrada')
    expect(screeningStatusLabel('invalidated')).toBe('Anulada')
  })
})
