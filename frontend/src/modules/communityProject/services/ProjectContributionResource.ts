import type { IProjectContribution } from '@/modules/communityProject/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ProjectContributionResource = createCrudApi<IProjectContribution, Record<string, unknown>, Record<string, unknown>, {
  decline: (_id: string) => Promise<IProjectContribution>
  revertDecline: (_id: string) => Promise<IProjectContribution>
}>({
  basePath: '/project_contribution',
  customMethods: () => ({
    decline: async (id) => {
      const { data } = await apiClient.post<{ data: IProjectContribution }>(`/project-contribution/${id}/decline`)
      return data.data
    },
    revertDecline: async (id) => {
      const { data } = await apiClient.post<{ data: IProjectContribution }>(`/project-contribution/${id}/revert-decline`)
      return data.data
    },
  }),
})
