import type { IProjectComment } from '@/modules/communityProject/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ProjectCommentResource = createCrudApi<
  IProjectComment,
  { community_project_id: string; body: string },
  Record<string, unknown>,
  {
    hide: (_id: string) => Promise<IProjectComment>
  }
>({
  basePath: '/project_comment',
  customMethods: () => ({
    // El backend oculta (no borra) el comentario; gate update.project_comment.
    hide: async (id) => {
      const { data } = await apiClient.post<{ data: IProjectComment }>(`/project-comment/${id}/hide`)
      return data.data
    },
  }),
})
