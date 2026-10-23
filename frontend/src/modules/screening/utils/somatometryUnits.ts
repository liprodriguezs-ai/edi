/**
 * Somatometry is stored in minor units (grams, millimetres -- hard rule 7) but
 * clinicians read and type kg and cm. These convert at the input boundary only.
 */
export type SomatometryDisplayUnit = 'kg' | 'cm'

const FACTOR: Record<SomatometryDisplayUnit, number> = { kg: 1000, cm: 10 }

export function fromStoredUnit(value: number | null | undefined, unit: SomatometryDisplayUnit): string {
  if (value == null) return ''
  return String(value / FACTOR[unit])
}

/** Accepts a decimal comma; returns whole minor units, or null when empty/invalid. */
export function toStoredUnit(value: string, unit: SomatometryDisplayUnit): number | null {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return null
  const parsed = Number(normalized)
  return Number.isFinite(parsed) ? Math.round(parsed * FACTOR[unit]) : null
}
