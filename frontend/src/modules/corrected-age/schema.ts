import { formatAgeDays } from '@/shared/utils/ageFormat'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { z } from 'zod'

export const correctedAgeFormSchema = z.object({
  birth_date: z.string().min(1, 'La fecha de nacimiento es obligatoria'),
  gestational_weeks: z
    .number({ message: 'Las semanas de gestación son obligatorias' })
    .int('Las semanas de gestación deben ser un número entero')
    .min(20, 'Las semanas de gestación deben estar entre 20 y 44')
    .max(44, 'Las semanas de gestación deben estar entre 20 y 44'),
  /** Optional: the server defaults it to the tenant's today. */
  reference_date: z.string().optional(),
})

export type CorrectedAgeFormValues = z.infer<typeof correctedAgeFormSchema>

export type CorrectedAgeNotApplicableReason = 'term_birth' | 'over_two_years'

export type AgeBreakdown = {
  days: number
  months: number
  remaining_days: number
}

export type CorrectedAgeGroup = {
  id: string
  code: string
  name: string
  ordinal: number
  min_age_months: number | null
  max_age_months: number | null
}

/** GET /screening/corrected-age — computed by the same rule the Prueba EDI applies. */
export type CorrectedAgeResult = {
  birth_date: string
  reference_date: string
  gestational_weeks: number
  applies: boolean
  reason: CorrectedAgeNotApplicableReason | null
  chronological: AgeBreakdown
  corrected: AgeBreakdown | null
  chronological_age_group: CorrectedAgeGroup | null
  corrected_age_group: CorrectedAgeGroup | null
  /** When the corrected age is under 1 month: the date it reaches 1 month (YYYY-MM-DD). */
  ready_from: string | null
}

const REASON_COPY: Record<CorrectedAgeNotApplicableReason, string> = {
  term_birth: 'No aplica: nació a las 37 semanas de gestación o más (embarazo de término).',
  over_two_years: 'No aplica: el niño tiene 2 años o más.',
}

export function notApplicableReasonCopy(reason: CorrectedAgeNotApplicableReason | null): string {
  return reason ? REASON_COPY[reason] : 'No aplica la corrección por prematurez.'
}

/** "3 meses 4 días" — months of 30 days, the convention of the EDI tables. */
export function formatAgeBreakdown(age: AgeBreakdown): string {
  return formatAgeDays(age.days)
}

/** The Prueba EDI starts at 1 month of corrected age (DGIS: "Prueba 1, un mes de edad -corregida-"). */
export function readyFromCopy(readyFrom: string): string {
  return `Aplica la Prueba EDI a partir del ${displayFormatDate(readyFrom, 'mediumDate')}, cuando su edad corregida cumpla 1 mes.`
}
