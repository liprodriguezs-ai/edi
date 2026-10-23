import type { IVisit, VisitFormValues } from '@/modules/visit/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const VisitResource = createCrudApi<
  IVisit,
  VisitFormValues,
  Record<string, unknown>,
  {
    // edi-screening-ui — remote SearchSelect for the "Aplicar tamizaje" visit
    // picker (design D8). Searches by folio (receipt_number); child/health
    // center relations are included so the option label can show identity.
    searchByTerm: (_term: string) => Promise<IVisit[]>
  }
>({
  basePath: '/visit',
  customMethods: (basePath) => ({
    searchByTerm: async (term) => {
      const { data } = await apiClient.post<{ data: IVisit[] }>(`${basePath}/search?limit=8`, {
        search: { value: term },
        includes: [{ relation: 'child' }, { relation: 'health_center' }],
        sort: [{ field: 'visit_date', direction: 'desc' }],
      })
      return data.data
    },
  }),
})
