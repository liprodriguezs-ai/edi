import type { FollowUpFormValues, FollowUpUpdateFormValues, IFollowUp } from '@/modules/followUp/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export interface CancelFollowUpPayload {
  reason: string
}

/**
 * `createCrudApi` serves index/show/store/update (Orion — REQ-FUP-006);
 * transitions are custom lifecycle actions (design §4/§5).
 */
export const FollowUpResource = createCrudApi<
  IFollowUp,
  FollowUpFormValues | FollowUpUpdateFormValues,
  Record<string, unknown>,
  {
    start: (_id: string) => Promise<{ data: IFollowUp }>
    complete: (_id: string) => Promise<{ data: IFollowUp }>
    cancel: (_id: string, _payload: CancelFollowUpPayload) => Promise<{ data: IFollowUp }>
  }
>({
  basePath: '/follow_up',
  customMethods: (basePath) => ({
    start: async (id) => {
      const { data } = await apiClient.post<{ data: IFollowUp }>(`${basePath}/${id}/start`)
      return data
    },
    complete: async (id) => {
      const { data } = await apiClient.post<{ data: IFollowUp }>(`${basePath}/${id}/complete`)
      return data
    },
    cancel: async (id, payload) => {
      const { data } = await apiClient.post<{ data: IFollowUp }>(`${basePath}/${id}/cancel`, payload)
      return data
    },
  }),
})
