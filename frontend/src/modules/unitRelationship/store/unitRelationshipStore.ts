import type { IUnitRelationship } from '@/modules/unitRelationship/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUnitRelationshipStore = createEntityStore<IUnitRelationship>('unitRelationship')
