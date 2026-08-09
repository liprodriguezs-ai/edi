import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const unitFeatureFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  is_active: z.boolean(),
})

export interface IUnitFeature extends IEntity {
  name: string
  is_active?: boolean
}

export type UnitFeatureFormValues = z.infer<typeof unitFeatureFormSchema>
