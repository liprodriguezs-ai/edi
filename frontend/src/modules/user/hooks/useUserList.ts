import type { IUser } from '@/modules/user/schema'
import { UserResource } from '@/modules/user/services/UserResource'
import { useUserStore } from '@/modules/user/store/userStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useUserList() {
  return useBaseList<IUser>({
    store: useUserStore,
    resource: UserResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        {
          relation: 'tenants',
        },
        {
          relation: 'roles',
        },
        {
          relation: 'role',
        },
      ],
    },
  })
}
