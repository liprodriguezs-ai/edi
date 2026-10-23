import type { CorrectedAgeFormValues, CorrectedAgeResult } from '@/modules/corrected-age/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

/**
 * The "Edad corregida" calculator is a read action of the Screening module
 * (`GET /screening/corrected-age`), so the client only formats what the server
 * computed -- it never re-implements the prematurity rule.
 */
export const CorrectedAgeResource = createCrudApi<
  CorrectedAgeResult,
  CorrectedAgeResult,
  Record<string, unknown>,
  { calculate: (_values: CorrectedAgeFormValues) => Promise<CorrectedAgeResult> }
>({
  basePath: '/screening',
  customMethods: (basePath) => ({
    calculate: async (values) => {
      const params: Record<string, string | number> = {
        birth_date: values.birth_date,
        gestational_weeks: values.gestational_weeks,
      }
      if (values.reference_date) params.reference_date = values.reference_date
      const { data } = await apiClient.get<{ data: CorrectedAgeResult }>(`${basePath}/corrected-age`, { params })
      return data.data
    },
  }),
})
