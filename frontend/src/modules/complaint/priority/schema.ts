import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const complaintPriorityFormSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(100, 'El nombre no puede superar 100 caracteres'),
  color: z.string().max(20, 'El color no puede superar 20 caracteres').optional(),
  is_active: z.boolean(),
  order: z.number().int().nullable(),
})

export interface IComplaintPriority extends IEntity {
  name: string
  color?: string | null
  is_active?: boolean
  order?: number | null
}

export type ComplaintPriorityFormValues = z.infer<typeof complaintPriorityFormSchema>
