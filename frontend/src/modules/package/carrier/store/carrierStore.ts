import type { IPackageCarrier } from '@/modules/package/carrier/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const usePackageCarrierStore = createEntityStore<IPackageCarrier>('packageCarrier')
