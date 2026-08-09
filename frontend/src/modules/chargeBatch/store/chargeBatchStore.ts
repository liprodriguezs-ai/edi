import type { IChargeBatch } from '@/modules/chargeBatch/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useChargeBatchStore = createEntityStore<IChargeBatch>('chargeBatch')
