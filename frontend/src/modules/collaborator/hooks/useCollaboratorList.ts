import type { ICollaborator } from '@/modules/collaborator/schema'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import { useCollaboratorStore } from '@/modules/collaborator/store/collaboratorStore'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { useBaseList } from '@/shared/hooks/useBaseList'

interface UseCollaboratorListOptions {
  limit?: number
}

export function useCollaboratorList(options?: UseCollaboratorListOptions) {
  return useBaseList<ICollaborator>({
    store: useCollaboratorStore,
    resource: CollaboratorResource,
    autoInit: true,
    initialSearch: {
      limit: options?.limit ?? APP_CONFIG.pageSize,
      includes: [
        { relation: 'user' },
        { relation: 'employee_position' },
        { relation: 'pay_frequency' },
        { relation: 'employee_shift' },
      ],
      sort: [{ field: 'created_at', direction: 'desc' }],
    },
  })
}
