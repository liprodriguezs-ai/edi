import type { IProvider } from '@/modules/provider/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ProviderResource = createCrudApi<IProvider, Record<string, unknown>>({
  basePath: '/provider',
})
