import type { IUnitType } from '@/modules/unitType/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUnitTypeStore = createEntityStore<IUnitType>('unitType')
