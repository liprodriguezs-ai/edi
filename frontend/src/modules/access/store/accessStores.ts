import type { IAccessLog, IBlacklistEntry, IVisitor } from '@/modules/access/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useVisitorStore = createEntityStore<IVisitor>('visitor')
export const useAccessLogStore = createEntityStore<IAccessLog>('accessLog')
export const useBlacklistStore = createEntityStore<IBlacklistEntry>('blacklist')
