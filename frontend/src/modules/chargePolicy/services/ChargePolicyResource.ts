import type { IChargePolicy } from '@/modules/chargePolicy/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ChargePolicyResource = createCrudApi<IChargePolicy, Record<string, unknown>>({
  basePath: '/charge_policy',
})
