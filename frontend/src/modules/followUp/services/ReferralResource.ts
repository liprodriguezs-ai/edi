import type { IReferral, ReferralFormValues } from '@/modules/followUp/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

/** `createCrudApi` serves index/show/store/update (Orion — REQ-FUP-007). */
export const ReferralResource = createCrudApi<IReferral, ReferralFormValues>({
  basePath: '/referral',
})
