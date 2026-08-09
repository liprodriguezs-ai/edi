import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const towerFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  floors_count: z.number().int().min(1, 'Mínimo 1 piso').nullable(),
  section_id: z.string().optional(),
  order: z.number().int().nullable(),
})

export interface ITower extends IEntity {
  name: string
  floors_count?: number | null
  section_id?: string | null
  order?: number | null
  section?: { id: string; name: string } | null
}

export type TowerFormValues = z.infer<typeof towerFormSchema>
