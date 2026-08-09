import type { ITenant } from '@/modules/tenant/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useTenantStore = createEntityStore<ITenant>('tenant')
