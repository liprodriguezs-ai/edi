import type { ICollaborator } from '@/modules/collaborator/schema'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import { useQuery } from '@tanstack/react-query'

const QUERY_KEY = ['collaborator', 'me'] as const

export function useCollaboratorMe() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => CollaboratorResource.me(),
    retry: (failureCount, retryError) => {
      const status = (retryError as { response?: { status?: number } })?.response?.status
      if (status === 404) return false
      return failureCount < 2
    },
  })

  return {
    collaborator: (data as ICollaborator) ?? null,
    isLoading,
    error: error ? 'No se pudo cargar tu expediente' : null,
    isNotFound: (error as { response?: { status?: number } })?.response?.status === 404,
    refetch,
  }
}
