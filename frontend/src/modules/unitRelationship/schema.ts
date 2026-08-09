import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const unitRelationshipFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  order: z.number().int().nullable(),
  is_active: z.boolean(),
})

export interface IUnitRelationship extends IEntity {
  name: string
  order?: number | null
  is_active?: boolean
}

export type UnitRelationshipFormValues = z.infer<typeof unitRelationshipFormSchema>
