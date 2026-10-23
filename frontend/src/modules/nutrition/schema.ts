import type { IEntity } from '@/shared/interfaces/Entity'
import { cmToMillimetres, kgToGrams } from '@/modules/nutrition/utils/nutritionalStatus'
import { z } from 'zod'

/**
 * Estado nutricional (S5). Los estados (P/T, P/E, T/E) y la edad en meses los
 * calcula SIEMPRE el servidor; el cliente sólo envía fecha, peso (g) y talla
 * (mm). Campos verificados contra Modules/Nutrition (snake_case 1:1).
 */

export type NutritionalStatusKey =
  | 'severe_malnutrition'
  | 'moderate_malnutrition'
  | 'mild_malnutrition'
  | 'normal_weight'
  | 'overweight'
  | 'obesity'
  | 'short_stature'
  | 'slightly_short_stature'
  | 'normal_stature'
  | 'slightly_tall_stature'
  | 'tall_stature'
  | 'no_reference'

export interface INutritionalStatusRef {
  id: string
  key: NutritionalStatusKey
  name: string
}

export interface INutritionalAssessment extends IEntity {
  child_id: string
  screening_id: string | null
  measured_on: string
  age_months: number
  weight_grams: number | null
  height_mm: number | null
  notes: string | null
  weight_for_height_status?: INutritionalStatusRef | null
  weight_for_age_status?: INutritionalStatusRef | null
  height_for_age_status?: INutritionalStatusRef | null
}

/** GET /nutritional_assessment/classify — vista previa calculada por el servidor. */
export interface INutritionalClassification {
  age_months: number
  weight_for_height_status: INutritionalStatusRef | null
  weight_for_age_status: INutritionalStatusRef | null
  height_for_age_status: INutritionalStatusRef | null
}

/** Cuerpo que viaja al API: medidas en unidades menores (g / mm). */
export interface NutritionalAssessmentPayload {
  child_id: string
  measured_on: string
  weight_grams: number | null
  height_mm: number | null
  notes: string | null
  include?: string
  [key: string]: unknown
}

const optionalMeasure = (min: number, max: number, message: string) =>
  z
    .string()
    .trim()
    .refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= min && Number(value) <= max), message)

export const nutritionalAssessmentFormSchema = z
  .object({
    measured_on: z.string().min(1, 'La fecha de medición es requerida'),
    weight_kg: optionalMeasure(0.5, 40, 'El peso debe estar entre 0.5 y 40 kg'),
    height_cm: optionalMeasure(35, 140, 'La talla debe estar entre 35 y 140 cm'),
    notes: z.string().optional(),
  })
  .refine((values) => values.weight_kg !== '' || values.height_cm !== '', {
    message: 'Captura al menos el peso o la talla',
    path: ['weight_kg'],
  })

export type NutritionalAssessmentFormValues = z.infer<typeof nutritionalAssessmentFormSchema>

const measureOrNull = (value: string, convert: (_n: number) => number): number | null =>
  value.trim() === '' ? null : convert(Number(value))

export function toNutritionalAssessmentPayload(
  childId: string,
  values: NutritionalAssessmentFormValues
): NutritionalAssessmentPayload {
  const notes = values.notes?.trim() ?? ''
  return {
    child_id: childId,
    measured_on: values.measured_on,
    weight_grams: measureOrNull(values.weight_kg, kgToGrams),
    height_mm: measureOrNull(values.height_cm, cmToMillimetres),
    notes: notes === '' ? null : notes,
  }
}
