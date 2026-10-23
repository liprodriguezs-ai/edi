import type { ChildFormValues, IChild } from '@/modules/child/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ChildResource = createCrudApi<
  IChild,
  ChildFormValues,
  Record<string, unknown>,
  {
    // edi-stimulation-sessions (D12) — remote SearchSelect for the session
    // subject / group-enrollment child pickers. Never exposed via /selects
    // (PII/volume smell, clinic D12 precedent).
    searchByTerm: (_term: string) => Promise<IChild[]>
  }
>({
  basePath: '/child',
  customMethods: (basePath) => ({
    searchByTerm: async (term) => {
      const { data } = await apiClient.post<{ data: IChild[] }>(`${basePath}/search?limit=8`, {
        search: { value: term },
      })
      return data.data
    },
  }),
})
