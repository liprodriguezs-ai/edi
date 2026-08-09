import type { ITower } from '@/modules/tower/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useTowerStore = createEntityStore<ITower>('tower')
