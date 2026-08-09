import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const complaintCategoryFormSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(100, 'El nombre no puede superar 100 caracteres'),
  is_active: z.boolean(),
  order: z.number().int().nullable(),
})

export interface IComplaintCategory extends IEntity {
  name: string
  is_active?: boolean
  order?: number | null
}

export type ComplaintCategoryFormValues = z.infer<typeof complaintCategoryFormSchema>
