import type { IBillingPackage } from '@/modules/billingPackage/schema'
import { BillingPackageResource } from '@/modules/billingPackage/services/BillingPackageResource'
import { useBillingPackageStore } from '@/modules/billingPackage/store/billingPackageStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useBillingPackageList() {
  return useBaseList<IBillingPackage>({
    store: useBillingPackageStore,
    resource: BillingPackageResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'sort_order', direction: 'asc' }],
    },
  })
}
