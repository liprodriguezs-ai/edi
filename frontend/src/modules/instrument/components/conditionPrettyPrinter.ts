import type { ICondition } from '@/modules/instrument/schema'

/**
 * Pretty-prints a global_scoring_rule condition (design §5.4 closed grammar)
 * as Mexican-Spanish text for the read-only inspector. Pure function, no
 * side effects — the raw jsonb is never mutated, only displayed.
 */

const SET_LABELS: Record<string, string> = {
  red_areas: 'áreas en rojo',
  yellow_areas: 'áreas en amarillo',
  green_areas: 'áreas en verde',
  alert_signs: 'señales de alerta',
  risk_factors: 'factores de riesgo',
}

const COLOR_LABELS: Record<string, string> = {
  red: 'rojo',
  yellow: 'amarillo',
  green: 'verde',
}

function setLabel(set: string): string {
  return SET_LABELS[set] ?? set
}

function colorLabel(color: string): string {
  return COLOR_LABELS[color] ?? color
}

const COMPARATOR_LABELS: Record<string, string> = {
  gte: 'mayor o igual a',
  eq: 'igual a',
  lte: 'menor o igual a',
}

export function prettyPrintCondition(condition: ICondition): string {
  if ('all' in condition) {
    if (condition.all.length === 0) {
      return 'siempre (regla por defecto)'
    }
    return condition.all.map(prettyPrintCondition).join(' Y ')
  }

  if ('any' in condition) {
    return condition.any.map(prettyPrintCondition).join(' O ')
  }

  if ('not' in condition) {
    return `NO (${prettyPrintCondition(condition.not)})`
  }

  if ('count' in condition) {
    const { of, gte, eq, lte } = condition.count
    const comparator =
      gte !== undefined ? ['gte', gte] : eq !== undefined ? ['eq', eq] : lte !== undefined ? ['lte', lte] : null
    if (!comparator) return `cantidad de ${setLabel(of)}`
    const [key, value] = comparator
    return `cantidad de ${setLabel(of)} ${COMPARATOR_LABELS[key as string]} ${value}`
  }

  if ('sum' in condition) {
    const { of, gte } = condition.sum
    const sets = of.map(setLabel).join(' + ')
    return `suma de (${sets}) mayor o igual a ${gte}`
  }

  if ('color_in' in condition) {
    const { areas, is } = condition.color_in
    return `alguna de [${areas.join(', ')}] está en ${colorLabel(is)}`
  }

  return 'condición desconocida'
}
