import type { ILockedReason } from '@/modules/screening/schema'

/**
 * D12 — `auto_derivation_key` (+ source field/value) → Spanish sentence for
 * a locked item/risk-factor (REQ-SUI-009). Keys mirror the backend's fixed
 * vocabulary: the head-circumference item lock and the 3 risk-factor
 * auto-derivation keys (RiskFactor::AUTO_DERIVATION_*).
 */

const SOURCE_LABELS: Record<string, string> = {
  head_circumference_mm: 'perímetro cefálico',
  gestational_age_weeks: 'edad gestacional',
  birth_weight_grams: 'peso al nacer',
  mother_age_at_birth_years: 'edad de la madre al nacimiento',
}

const UNIT_SUFFIX: Record<string, string> = {
  head_circumference_mm: ' mm',
  gestational_age_weeks: ' semanas',
  birth_weight_grams: ' g',
  mother_age_at_birth_years: ' años',
}

export function lockedReasonCopy(reason: ILockedReason | null | undefined): string {
  if (!reason) return 'Esta respuesta se deriva automáticamente.'

  const label = SOURCE_LABELS[reason.source_field] ?? reason.source_field
  const unit = UNIT_SUFFIX[reason.source_field] ?? ''

  return `Derivado automáticamente a partir de ${label} registrado (${reason.value}${unit}).`
}

/** Spanish name of a perinatal/somatometry source field ("perímetro cefálico"); never the raw identifier. */
export function sourceFieldLabel(field: string): string {
  return SOURCE_LABELS[field] ?? 'un dato del registro'
}

