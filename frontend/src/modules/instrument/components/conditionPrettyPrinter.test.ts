import { prettyPrintCondition } from '@/modules/instrument/components/conditionPrettyPrinter'
import type { ICondition } from '@/modules/instrument/schema'
import { describe, expect, it } from 'vitest'

describe('prettyPrintCondition', () => {
  it('renders the catch-all condition', () => {
    const condition: ICondition = { all: [] }
    expect(prettyPrintCondition(condition)).toBe('siempre (regla por defecto)')
  })

  it('renders all with sub-conditions joined by Y', () => {
    const condition: ICondition = {
      all: [
        { count: { of: 'yellow_areas', gte: 1 } },
        { color_in: { areas: ['MG', 'MF', 'LE'], is: 'yellow' } },
      ],
    }
    expect(prettyPrintCondition(condition)).toBe(
      'cantidad de áreas en amarillo mayor o igual a 1 Y alguna de [MG, MF, LE] está en amarillo'
    )
  })

  it('renders any with sub-conditions joined by O', () => {
    const condition: ICondition = {
      any: [{ count: { of: 'red_areas', gte: 1 } }, { count: { of: 'yellow_areas', gte: 2 } }],
    }
    expect(prettyPrintCondition(condition)).toBe(
      'cantidad de áreas en rojo mayor o igual a 1 O cantidad de áreas en amarillo mayor o igual a 2'
    )
  })

  it('renders not wrapping its sub-condition', () => {
    const condition: ICondition = { not: { count: { of: 'red_areas', gte: 1 } } }
    expect(prettyPrintCondition(condition)).toBe('NO (cantidad de áreas en rojo mayor o igual a 1)')
  })

  it('renders count with each comparator', () => {
    expect(prettyPrintCondition({ count: { of: 'red_areas', gte: 1 } })).toBe(
      'cantidad de áreas en rojo mayor o igual a 1'
    )
    expect(prettyPrintCondition({ count: { of: 'red_areas', eq: 2 } })).toBe('cantidad de áreas en rojo igual a 2')
    expect(prettyPrintCondition({ count: { of: 'red_areas', lte: 3 } })).toBe(
      'cantidad de áreas en rojo menor o igual a 3'
    )
  })

  it('renders sum of multiple sets', () => {
    const condition: ICondition = { sum: { of: ['alert_signs', 'risk_factors'], gte: 2 } }
    expect(prettyPrintCondition(condition)).toBe('suma de (señales de alerta + factores de riesgo) mayor o igual a 2')
  })

  it('renders color_in with its area list and color', () => {
    const condition: ICondition = { color_in: { areas: ['SO', 'CO'], is: 'yellow' } }
    expect(prettyPrintCondition(condition)).toBe('alguna de [SO, CO] está en amarillo')
  })
})
