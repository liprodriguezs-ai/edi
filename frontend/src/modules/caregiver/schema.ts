import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const caregiverFormSchema = z.object({
  first_name: z.string().min(1, 'El nombre es requerido'),
  last_name_paternal: z.string().min(1, 'El apellido paterno es requerido'),
  last_name_maternal: z.string().optional(),
  birth_date: z.string().optional(),
  education_level_id: z.string().min(1, 'La escolaridad es requerida'),
  occupation_id: z.string().min(1, 'La ocupación es requerida'),
  population_type_id: z.string().min(1, 'El tipo de población es requerido'),
  phone: z.string().min(1, 'El teléfono es requerido'),
  email: z.string().email('Correo inválido').optional().or(z.literal('')),
  has_social_program: z.boolean(),
})

export interface ICaregiver extends IEntity {
  first_name: string
  last_name_paternal: string
  last_name_maternal?: string | null
  birth_date?: string | null
  education_level_id: string
  occupation_id: string
  population_type_id: string
  phone: string
  email?: string | null
  has_social_program: boolean
}

export type CaregiverFormValues = z.infer<typeof caregiverFormSchema>

export function caregiverFullName(
  caregiver: Pick<ICaregiver, 'first_name' | 'last_name_paternal' | 'last_name_maternal'>
): string {
  return [caregiver.first_name, caregiver.last_name_paternal, caregiver.last_name_maternal]
    .filter((part) => part && part.trim() !== '')
    .join(' ')
}

// ── child_caregiver (link) ──────────────────────────────────────────────────

export interface IChildCaregiver extends IEntity {
  child_id: string
  caregiver_id: string
  caregiver_relationship_id: string
  is_primary: boolean
  caregiver?: ICaregiver | null
  caregiver_relationship?: { id: string; name: string } | null
}

export const childCaregiverFormSchema = z.object({
  child_id: z.string().min(1),
  caregiver_id: z.string().min(1, 'El cuidador es requerido'),
  caregiver_relationship_id: z.string().min(1, 'El parentesco es requerido'),
  is_primary: z.boolean().optional(),
})

export type ChildCaregiverFormValues = z.infer<typeof childCaregiverFormSchema>
