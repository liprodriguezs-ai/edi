import type { IPackageType } from '@/modules/package/type/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const usePackageTypeStore = createEntityStore<IPackageType>('packageType')
