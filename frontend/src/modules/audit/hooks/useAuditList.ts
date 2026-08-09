import type { IAudit } from '@/modules/audit/schema'
import { AuditResource } from '@/modules/audit/services/AuditResource'
import { useAuditStore } from '@/modules/audit/store/auditStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useAuditList() {
  return useBaseList<IAudit>({
    store: useAuditStore,
    resource: AuditResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        {
          relation: 'user',
        },
      ],
    },
  })
}

