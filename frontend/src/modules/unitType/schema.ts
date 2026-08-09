import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const unitTypeFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  order: z.number().int().nullable(),
  is_active: z.boolean(),
  requires_floor: z.boolean(),
})

export interface IUnitType extends IEntity {
  name: string
  order?: number | null
  is_active?: boolean
  requires_floor?: boolean
}

export type UnitTypeFormValues = z.infer<typeof unitTypeFormSchema>
