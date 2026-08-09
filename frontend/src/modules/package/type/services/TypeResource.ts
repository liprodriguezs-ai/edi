import type { IPackageType, PackageTypeFormValues } from '@/modules/package/type/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const TypeResource = createCrudApi<IPackageType, PackageTypeFormValues>({
  basePath: '/package_type',
})
