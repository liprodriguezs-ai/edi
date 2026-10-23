import type { IFollowUp } from '@/modules/followUp/schema'
import { FollowUpResource } from '@/modules/followUp/services/FollowUpResource'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'

/** Read-only detail via React Query, cache-managed. */
export function useFollowUpDetail(followUpId: string | undefined) {
  const queryClient = useQueryClient()

  const { entity, isLoading, isError, notFound, refetch } = useEntityDetail<IFollowUp>({
    queryKey: ['follow-up-detail', followUpId],
    queryFn: () => FollowUpResource.show(followUpId as string),
    enabled: Boolean(followUpId),
  })

  const invalidate = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['follow-up-detail', followUpId] })
  }, [queryClient, followUpId])

  return {
    followUp: entity ?? null,
    isLoading,
    isError,
    notFound,
    refetch,
    invalidate,
  }
}
