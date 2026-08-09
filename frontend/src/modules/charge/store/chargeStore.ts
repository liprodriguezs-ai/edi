import type { ICharge } from '@/modules/charge/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useChargeStore = createEntityStore<ICharge>('charge')
