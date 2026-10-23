import type { IScreening } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'

/**
 * REQ-SUI-008/D7 — read-only detail via React Query, cache-managed and
 * shareable. NEVER feeds the wizard store (D7): the wizard hydrates its own
 * `screeningDraftStore` independently.
 *
 * No `?include=` is passed: `ScreeningController::alwaysIncludes()` loads
 * the full includes() allowlist unconditionally on every read (deliberate
 * backend design — "the whole point of this resource is the computed
 * result"), so the client-requested include list would be redundant.
 */
export function useScreeningDetail(screeningId: string | undefined) {
  const queryClient = useQueryClient()

  const { entity, isLoading, isError, notFound, refetch } = useEntityDetail<IScreening>({
    queryKey: ['screening-detail', screeningId],
    queryFn: () => ScreeningResource.show(screeningId as string),
    enabled: Boolean(screeningId),
  })

  const invalidate = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['screening-detail', screeningId] })
  }, [queryClient, screeningId])

  return {
    screening: entity ?? null,
    isLoading,
    isError,
    notFound,
    refetch,
    invalidate,
  }
}
