import type { CompanyFormValues, ICompany } from '@/modules/company/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const CompanyResource = createCrudApi<
  ICompany,
  CompanyFormValues,
  Record<string, unknown>,
  { me: () => Promise<ICompany> }
>({
  basePath: '/company',
  customMethods: (basePath) => ({
    me: async () => {
      const { data } = await apiClient.get<{ data: ICompany }>(`${basePath}/me`)
      return data.data
    },
  }),
})

