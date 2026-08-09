import type { IAudit } from '@/modules/audit/schema'
import type { AuditFormValues } from '@/modules/audit/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const AuditResource = createCrudApi<IAudit, AuditFormValues>({
  basePath: '/audit',
})
