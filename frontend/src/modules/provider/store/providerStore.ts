import type { IProvider } from '@/modules/provider/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useProviderStore = createEntityStore<IProvider>('provider')
