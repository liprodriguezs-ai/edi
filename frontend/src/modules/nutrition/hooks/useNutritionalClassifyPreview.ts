import type { INutritionalClassification } from '@/modules/nutrition/schema'
import { NutritionalAssessmentResource } from '@/modules/nutrition/services/NutritionalAssessmentResource'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

const DEBOUNCE_MS = 400

interface PreviewInput {
  childId: string
  measuredOn: string
  weightGrams: number | null
  heightMm: number | null
}

/**
 * Vista previa en vivo: el SERVIDOR clasifica (invariante 1); el cliente sólo
 * espera a que el usuario deje de teclear para no disparar una consulta por
 * tecla. No consulta sin fecha ni sin al menos una medida.
 */
export function useNutritionalClassifyPreview(input: PreviewInput) {
  const { childId, measuredOn, weightGrams, heightMm } = input
  const [debounced, setDebounced] = useState<PreviewInput>(input)

  useEffect(() => {
    const handle = setTimeout(() => setDebounced({ childId, measuredOn, weightGrams, heightMm }), DEBOUNCE_MS)
    return () => clearTimeout(handle)
  }, [childId, measuredOn, weightGrams, heightMm])

  const enabled =
    debounced.measuredOn !== '' && (debounced.weightGrams !== null || debounced.heightMm !== null)

  return useQuery({
    queryKey: ['nutritional_assessment', 'classify', debounced],
    queryFn: (): Promise<INutritionalClassification> =>
      NutritionalAssessmentResource.classify({
        child_id: debounced.childId,
        measured_on: debounced.measuredOn,
        weight_grams: debounced.weightGrams,
        height_mm: debounced.heightMm,
      }),
    enabled,
    retry: false,
    placeholderData: keepPreviousData,
  })
}
