import type { IProjectComment } from '@/modules/communityProject/schema'
import { ProjectCommentResource } from '@/modules/communityProject/services/ProjectCommentResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/** Hilo de comentarios de un proyecto comunitario (§11.6): lista + publicar + ocultar. */
export function useProjectComments(projectId: string) {
  const queryClient = useQueryClient()

  const { data: comments = [], isLoading } = useQuery({
    queryKey: ['project', 'comments', projectId],
    queryFn: async () => {
      const { data } = await ProjectCommentResource.search({
        limit: 100,
        filters: [{ field: 'community_project_id', operator: '=', value: projectId }],
        includes: [{ relation: 'user' }],
        sort: [{ field: 'created_at', direction: 'desc' }],
      })
      return data
    },
    enabled: Boolean(projectId),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['project', 'comments', projectId] })

  const postMutation = useMutation({
    mutationFn: (body: string) => ProjectCommentResource.create({ community_project_id: projectId, body }),
    onSuccess: () => { void refresh() },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo publicar el comentario'),
  })

  const hideMutation = useMutation({
    mutationFn: (comment: IProjectComment) => ProjectCommentResource.hide(comment.id),
    onSuccess: () => { toast.success('Comentario ocultado'); void refresh() },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo ocultar el comentario'),
  })

  return { comments, isLoading, postMutation, hideMutation }
}
