import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const sectionFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  order: z.number().int().nullable(),
})

export interface ISection extends IEntity {
  name: string
  order?: number | null
}

export type SectionFormValues = z.infer<typeof sectionFormSchema>
