import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

const optionalRangedInt = (min: number, max: number) =>
  z
    .number()
    .int('Debe ser un número entero')
    .min(min, `El mínimo es ${min}`)
    .max(max, `El máximo es ${max}`)
    .nullable()
    .optional()

export const birthRecordFormSchema = z.object({
  child_id: z.string().min(1),
  gestational_age_weeks: optionalRangedInt(20, 44),
  birth_weight_grams: optionalRangedInt(400, 7000),
  birth_length_mm: optionalRangedInt(200, 650),
  prenatal_visit_count: optionalRangedInt(0, 40),
  had_pregnancy_complication: z.boolean().nullable().optional(),
  had_delivery_complication: z.boolean().nullable().optional(),
  nicu_days: optionalRangedInt(0, 365),
  mother_age_at_birth_years: optionalRangedInt(10, 60),
  early_breastfeeding: z.boolean().nullable().optional(),
  breastfeeding_months: optionalRangedInt(0, 71),
})

export interface IBirthRecord extends IEntity {
  child_id: string
  gestational_age_weeks?: number | null
  birth_weight_grams?: number | null
  birth_length_mm?: number | null
  prenatal_visit_count?: number | null
  had_pregnancy_complication?: boolean | null
  had_delivery_complication?: boolean | null
  nicu_days?: number | null
  mother_age_at_birth_years?: number | null
  early_breastfeeding?: boolean | null
  breastfeeding_months?: number | null
}

export type BirthRecordFormValues = z.infer<typeof birthRecordFormSchema>

/** REQ-CH-007 / REQ-XC-006 — tri-state Sí/No/Se ignora, nunca un default false. */
export type TriState = 'yes' | 'no' | 'unknown'

export function booleanToTriState(value: boolean | null | undefined): TriState {
  if (value === true) return 'yes'
  if (value === false) return 'no'
  return 'unknown'
}

export function triStateToBoolean(value: TriState): boolean | null {
  if (value === 'yes') return true
  if (value === 'no') return false
  return null
}
