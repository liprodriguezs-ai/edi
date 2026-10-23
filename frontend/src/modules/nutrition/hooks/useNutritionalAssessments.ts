import type { INutritionalAssessment } from '@/modules/nutrition/schema'
import { NutritionalAssessmentResource } from '@/modules/nutrition/services/NutritionalAssessmentResource'
import { useQuery } from '@tanstack/react-query'

const HISTORY_LIMIT = 100

export const nutritionQueryKeys = {
  child: (childId: string) => ['nutritional_assessment', 'child', childId] as const,
  screening: (screeningId: string, measuresKey = '') =>
    ['nutritional_assessment', 'screening', screeningId, measuresKey] as const,
}

/** Historial de mediciones del niño, la más reciente primero. */
export function useChildNutritionalAssessments(childId: string) {
  return useQuery({
    queryKey: nutritionQueryKeys.child(childId),
    queryFn: async (): Promise<INutritionalAssessment[]> => {
      const response = await NutritionalAssessmentResource.search({
        filters: [{ field: 'child_id', operator: '=', value: childId }],
        sort: [
          { field: 'measured_on', direction: 'desc' },
          { field: 'created_at', direction: 'desc' },
        ],
        limit: HISTORY_LIMIT,
      })
      return response.data
    },
  })
}

/**
 * La medición sincronizada desde una Prueba EDI (o null si no hubo somatometría).
 * `measuresKey` cambia cuando se edita la somatometría para volver a pedirla.
 */
export function useScreeningNutritionalAssessment(screeningId: string, measuresKey = '') {
  return useQuery({
    queryKey: nutritionQueryKeys.screening(screeningId, measuresKey),
    queryFn: async (): Promise<INutritionalAssessment | null> => {
      const response = await NutritionalAssessmentResource.search({
        filters: [{ field: 'screening_id', operator: '=', value: screeningId }],
        limit: 1,
      })
      return response.data[0] ?? null
    },
  })
}
