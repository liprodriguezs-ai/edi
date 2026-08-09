import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const unitStatusFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  color: z.string().optional(),
  order: z.number().int().nullable(),
  is_active: z.boolean(),
  is_billable: z.boolean(),
})

export interface IUnitStatus extends IEntity {
  name: string
  color?: string | null
  order?: number | null
  is_active?: boolean
  is_billable?: boolean
}

export type UnitStatusFormValues = z.infer<typeof unitStatusFormSchema>
