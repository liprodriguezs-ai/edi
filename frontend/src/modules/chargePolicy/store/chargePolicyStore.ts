import type { IChargePolicy } from '@/modules/chargePolicy/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useChargePolicyStore = createEntityStore<IChargePolicy>('chargePolicy')
