import type { IAudit } from '@/modules/audit/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useAuditStore = createEntityStore<IAudit>('audit');

