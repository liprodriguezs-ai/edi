import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

const CLUES_REGEX = /^[A-Za-z]{5}[0-9]{6}$/

export const healthCenterFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  clues_code: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || CLUES_REGEX.test(v.trim()), {
      message: 'El CLUES debe tener 5 letras y 6 dígitos (ej. DFSSA003962)',
    }),
  municipality_id: z.string().min(1, 'El municipio es requerido'),
  address: z.string().min(1, 'La dirección es requerida'),
  is_active: z.boolean(),
})

export interface IHealthCenter extends IEntity {
  name: string
  clues_code?: string | null
  municipality_id: string
  address: string
  is_active: boolean
  municipality?: { id: string; name: string } | null
}

export type HealthCenterFormValues = z.infer<typeof healthCenterFormSchema>
