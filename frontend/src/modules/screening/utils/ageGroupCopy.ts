import type { IAgeGroupOption, IAgeGroupRef, IScreening } from '@/modules/screening/schema'

/** Query param of `/screening/apply/:visitId` carrying the group picked for a "Prueba EDI a elección" (S2). */
export const CHOSEN_GROUP_PARAM = 'grupo'

type AgeGroupLike = Pick<IAgeGroupRef, 'code' | 'ordinal' | 'min_age_months' | 'max_age_months'>

function monthsLabel(months: number): string {
  return months === 1 ? '1 mes' : `${months} meses`
}

/** "10 a 12 meses" / "1 mes"; null when the group carries no month range. */
export function ageGroupRangeLabel(group: AgeGroupLike): string | null {
  const { min_age_months: min, max_age_months: max } = group
  if (min == null || max == null) return null
  if (min === max) return monthsLabel(min)
  return `${min} a ${max} meses`
}

/** "Grupo 7" — the legacy wording; falls back to the code when there is no ordinal. */
export function ageGroupNumberLabel(group: Pick<IAgeGroupRef, 'code' | 'ordinal'>): string {
  return group.ordinal != null ? `Grupo ${group.ordinal}` : group.code
}

/** "Grupo 7 · 10 a 12 meses" — the option label of the "Evaluación a realizar" select. */
export function ageGroupOptionLabel(group: AgeGroupLike | IAgeGroupOption): string {
  const range = ageGroupRangeLabel(group)
  const number = ageGroupNumberLabel(group)
  return range ? `${number} · ${range}` : number
}

export function isAgeGroupChosen(screening: Pick<IScreening, 'age_group_selection_mode'>): boolean {
  return screening.age_group_selection_mode === 'chosen'
}

/**
 * True only for the corrected-age substitution. A chosen group also differs from
 * the chronological one, but that is the evaluator's choice, not a substitution.
 */
export function hasCorrectedAgeSubstitution(
  screening: Pick<IScreening, 'age_group_selection_mode' | 'age_group_id' | 'applied_age_group_id'>
): boolean {
  return !isAgeGroupChosen(screening) && screening.applied_age_group_id !== screening.age_group_id
}
