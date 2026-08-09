import type { ITenant } from '@/modules/tenant/schema'
import { TenantResource } from '@/modules/tenant/services/TenantResource'
import { useTenantStore } from '@/modules/tenant/store/tenantStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useTenantList() {
  return useBaseList<ITenant>({
    store: useTenantStore,
    resource: TenantResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
    },
  })
}
