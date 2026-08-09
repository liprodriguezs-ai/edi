import type { ICollaborator } from '@/modules/collaborator/schema'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'

export function useCollaboratorDetail(collaboratorId: string) {
  const queryClient = useQueryClient()

  const { entity, isLoading, isError, notFound } = useEntityDetail<ICollaborator>({
    queryKey: ['collaborator-detail', collaboratorId],
    queryFn: () =>
      CollaboratorResource.show(collaboratorId, {
        include:
          'user,job_position,area,contract_type,company,nationality,marital_status,work_shift,salary',
      } as Record<string, unknown>),
    enabled: Boolean(collaboratorId),
  })

  const refresh = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['collaborator-detail', collaboratorId] })
  }, [queryClient, collaboratorId])

  return {
    collaborator: entity ?? null,
    isLoading,
    error: isError ? 'Error al cargar el colaborador' : null,
    notFound,
    refresh,
  }
}
