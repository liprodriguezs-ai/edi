import type { IPackage } from '@/modules/package/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const usePackageStore = createEntityStore<IPackage>('package')
