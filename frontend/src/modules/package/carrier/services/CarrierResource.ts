import type { IPackageCarrier, PackageCarrierFormValues } from '@/modules/package/carrier/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const CarrierResource = createCrudApi<IPackageCarrier, PackageCarrierFormValues>({
  basePath: '/package_carrier',
})
