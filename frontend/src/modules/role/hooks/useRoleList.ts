import type { IRole } from '@/modules/role/schema'
import { RoleResource } from '@/modules/role/services/RoleResource'
import { useRoleStore } from '@/modules/role/store/roleStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useRoleList() {
  return useBaseList<IRole>({
    store: useRoleStore,
    resource: RoleResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'name', direction: 'asc' }],
    },
  })
}
