import type { IUnitUser } from '@/modules/unit/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUnitUserStore = createEntityStore<IUnitUser>('unitUser')
