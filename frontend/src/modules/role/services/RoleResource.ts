import type { IRolePermissionModule, IRole, RoleFormValues } from '@/modules/role/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

interface RoleCustomMethods {
  getPermissions: (id: string) => Promise<IRolePermissionModule[]>
  setPermissions: (id: string, permissions: Record<string, Record<string, { uuid: string; active: boolean }>>) => Promise<string[]>
}

export const RoleResource = createCrudApi<IRole, RoleFormValues, Record<string, unknown>, RoleCustomMethods>({
  basePath: '/role',
  customMethods: (basePath) => ({
    getPermissions: async (id: string) => {
      const { data } = await apiClient.get<{ data: IRolePermissionModule[] }>(`${basePath}/permissions/${id}`)
      return data.data
    },
    setPermissions: async (id: string, permissions) => {
      const { data } = await apiClient.post<{ data: string[] }>(`${basePath}/permissions/${id}`, { permissions })
      return data.data
    },
  }),
})
