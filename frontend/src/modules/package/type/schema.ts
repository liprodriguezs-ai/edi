import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const packageTypeFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  is_active: z.boolean(),
  order: z.number().int().nullable(),
})

export interface IPackageType extends IEntity {
  name: string
  is_active?: boolean
  order?: number | null
  tenant_id?: string
}

export type PackageTypeFormValues = z.infer<typeof packageTypeFormSchema>
