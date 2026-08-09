import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const roleFormSchema = z.object({
  description: z.string().min(1, 'El nombre es requerido'),
  name: z.string().min(1, 'El slug es requerido'),
})

export interface IRole extends IEntity {
  name: string
  system: boolean
}

export type RoleFormValues = z.infer<typeof roleFormSchema>

export interface IRolePermission {
  uuid: string
  name: string
  active: boolean
}

export interface IRolePermissionModule {
  id: string
  name: string
  group: string
  permissions: IRolePermission[]
}
