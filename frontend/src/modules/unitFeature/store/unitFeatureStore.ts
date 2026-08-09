import type { IUnitFeature } from '@/modules/unitFeature/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUnitFeatureStore = createEntityStore<IUnitFeature>('unitFeature')
