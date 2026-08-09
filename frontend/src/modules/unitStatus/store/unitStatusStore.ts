import type { IUnitStatus } from '@/modules/unitStatus/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUnitStatusStore = createEntityStore<IUnitStatus>('unitStatus')
