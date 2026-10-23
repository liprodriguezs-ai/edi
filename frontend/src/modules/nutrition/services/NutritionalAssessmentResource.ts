import type {
  INutritionalAssessment,
  INutritionalClassification,
  NutritionalAssessmentPayload,
} from '@/modules/nutrition/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export interface ClassifyParams {
  child_id: string
  measured_on: string
  weight_grams?: number | null
  height_mm?: number | null
}

/** Orion CRUD + vista previa de clasificación calculada por el servidor. */
export const NutritionalAssessmentResource = createCrudApi<
  INutritionalAssessment,
  NutritionalAssessmentPayload,
  Record<string, unknown>,
  {
    classify: (_params: ClassifyParams) => Promise<INutritionalClassification>
  }
>({
  basePath: '/nutritional_assessment',
  customMethods: (basePath) => ({
    classify: async (params) => {
      const query: Record<string, string> = { child_id: params.child_id, measured_on: params.measured_on }
      if (params.weight_grams !== null && params.weight_grams !== undefined) {
        query.weight_grams = String(params.weight_grams)
      }
      if (params.height_mm !== null && params.height_mm !== undefined) {
        query.height_mm = String(params.height_mm)
      }
      const { data } = await apiClient.get<{ data: INutritionalClassification }>(`${basePath}/classify`, {
        params: query,
      })
      return data.data
    },
  }),
})
