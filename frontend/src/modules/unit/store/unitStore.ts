import type { IUnit } from '@/modules/unit/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUnitStore = createEntityStore<IUnit>('unit')
