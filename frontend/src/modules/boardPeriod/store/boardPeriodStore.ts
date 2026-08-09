import type { IBoardPeriod } from '@/modules/boardPeriod/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useBoardPeriodStore = createEntityStore<IBoardPeriod>('boardPeriod')
