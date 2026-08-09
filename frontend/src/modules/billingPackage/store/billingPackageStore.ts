import type { IBillingPackage } from '@/modules/billingPackage/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useBillingPackageStore = createEntityStore<IBillingPackage>('BillingPackage')
