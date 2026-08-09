import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const childFormSchema = z.object({
  first_name: z.string().min(1, 'El nombre es requerido'),
  last_name_paternal: z.string().min(1, 'El apellido paterno es requerido'),
  last_name_maternal: z.string().optional(),
  birth_date: z.string().min(1, 'La fecha de nacimiento es requerida'),
  sex_id: z.string().min(1, 'El sexo es requerido'),
  curp: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || v.trim().length === 18, {
      message: 'El CURP debe tener 18 caracteres',
    }),
  external_id: z.string().optional(),
  health_center_id: z.string().min(1, 'El centro de salud es requerido'),
  is_schooled: z.boolean(),
  notes: z.string().optional(),
})

export interface IChild extends IEntity {
  first_name: string
  last_name_paternal: string
  last_name_maternal?: string | null
  birth_date: string
  sex_id: string
  curp?: string | null
  curp_mismatch?: boolean
  external_id?: string | null
  health_center_id: string
  is_schooled: boolean
  notes?: string | null
  sex?: { id: string; name: string } | null
  health_center?: { id: string; name: string } | null
}

export type ChildFormValues = z.infer<typeof childFormSchema>

/** Construye el nombre completo mostrando solo lo que existe (D-13). */
export function childFullName(child: Pick<IChild, 'first_name' | 'last_name_paternal' | 'last_name_maternal'>): string {
  return [child.first_name, child.last_name_paternal, child.last_name_maternal]
    .filter((part) => part && part.trim() !== '')
    .join(' ')
}
