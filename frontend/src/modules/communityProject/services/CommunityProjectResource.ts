import type { ICommunityProject, ProjectSummary } from '@/modules/communityProject/schema'
import type { IPayment } from '@/modules/payment/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const CommunityProjectResource = createCrudApi<
  ICommunityProject,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    activate: (_id: string) => Promise<ICommunityProject>
    close: (_id: string) => Promise<ICommunityProject>
    cancelProject: (_id: string, _reason?: string) => Promise<ICommunityProject>
    summary: (_id: string) => Promise<ProjectSummary>
    contributionPayments: (_id: string, _params?: { status?: 'pending' }) => Promise<IPayment[]>
    uploadMedia: (_id: string, _file: File) => Promise<unknown>
    deleteMedia: (_id: string, _mediaId: string) => Promise<void>
    sendReminder: (_id: string) => Promise<{ sent: number }>
  }
>({
  basePath: '/community_project',
  customMethods: () => ({
    activate: async (id) => {
      const { data } = await apiClient.post<{ data: ICommunityProject }>(
        `/community-project/${id}/activate`
      )
      return data.data
    },
    close: async (id) => {
      const { data } = await apiClient.post<{ data: ICommunityProject }>(
        `/community-project/${id}/close`
      )
      return data.data
    },
    cancelProject: async (id, reason) => {
      const { data } = await apiClient.post<{ data: ICommunityProject }>(
        `/community-project/${id}/cancel`,
        { reason }
      )
      return data.data
    },
    summary: async (id) => {
      const { data } = await apiClient.get<{ data: ProjectSummary }>(
        `/community-project/${id}/summary`
      )
      return data.data
    },
    contributionPayments: async (id, params) => {
      const { data } = await apiClient.get<{ data: IPayment[] }>(
        `/community-project/${id}/contribution-payments`,
        { params }
      )
      return data.data
    },
    uploadMedia: async (id, file) => {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await apiClient.post(`/community-project/${id}/media`, formData)
      return data
    },
    deleteMedia: async (id, mediaId) => {
      await apiClient.delete(`/community-project/${id}/media/${mediaId}`)
    },
    sendReminder: async (id) => {
      const { data } = await apiClient.post<{ data: { sent: number } }>(
        `/community-project/${id}/send-contribution-reminder`
      )
      return data.data
    },
  }),
})
