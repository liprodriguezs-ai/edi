import type {
  ComplaintFormValues,
  IComplaint,
  IComplaintHistoryEntry,
  IComplaintMessage,
} from '@/modules/complaint/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

interface IEligibleAssignee {
  id: string
  name: string
}

export const ComplaintResource = createCrudApi<
  IComplaint,
  ComplaintFormValues,
  Record<string, unknown>,
  {
    create: (_payload: ComplaintFormValues | FormData) => Promise<IComplaint>
    transition: (_id: string, _statusId: string) => Promise<IComplaint>
    assign: (_id: string, _userId: string) => Promise<IComplaint>
    eligibleAssignees: (_id: string) => Promise<IEligibleAssignee[]>
    postMessage: (_id: string, _payload: FormData) => Promise<IComplaintMessage>
    messages: (_id: string) => Promise<IComplaintMessage[]>
    history: (_id: string) => Promise<IComplaintHistoryEntry[]>
    registrationEligibility: () => Promise<{ can_register: boolean }>
  }
>({
  basePath: '/complaint',
  customMethods: (basePath) => ({
    /**
     * Overrides the generic create() to pass skipPermissionsModal: true (review
     * W5) — a 403 here is the business rule complaint.reporter_role_keys
     * (ADR-5), not a coarse permission gap, and ComplaintForm already surfaces
     * it inline via toast.
     */
    create: async (payload) => {
      const { data } = await apiClient.post<{ data: IComplaint }>(basePath, payload, {
        skipPermissionsModal: true,
      })
      return data.data
    },
    transition: async (id, statusId) => {
      const { data } = await apiClient.post<{ data: IComplaint }>(
        `${basePath}/${id}/transition`,
        { complaint_status_id: statusId },
        { skipPermissionsModal: true }
      )
      return data.data
    },
    assign: async (id, userId) => {
      const { data } = await apiClient.post<{ data: IComplaint }>(
        `${basePath}/${id}/assign`,
        { assigned_user_id: userId },
        { skipPermissionsModal: true }
      )
      return data.data
    },
    eligibleAssignees: async (id) => {
      const { data } = await apiClient.get<{ data: IEligibleAssignee[] }>(
        `${basePath}/${id}/eligible-assignees`
      )
      return data.data
    },
    postMessage: async (id, payload) => {
      const { data } = await apiClient.post<{ data: IComplaintMessage }>(
        `${basePath}/${id}/message`,
        payload,
        { skipPermissionsModal: true }
      )
      return data.data
    },
    messages: async (id) => {
      const { data } = await apiClient.get<{ data: IComplaintMessage[] }>(
        `${basePath}/${id}/messages`,
        { skipPermissionsModal: true }
      )
      return data.data
    },
    /**
     * Translated change-history timeline (spec "History"), read server-side
     * from the existing Laravel Auditing trail. Fetched lazily — only when
     * the "Historial de cambios" section is first expanded (infra-cost rule),
     * see ComplaintHistorySection.
     */
    history: async (id) => {
      const { data } = await apiClient.get<{ data: IComplaintHistoryEntry[] }>(
        `${basePath}/${id}/history`
      )
      return data.data
    },
    /**
     * Whether the CALLER may register a complaint (mirrors the runtime
     * complaint.reporter_role_keys gate ComplaintService::register enforces
     * on submit — see ComplaintListPage, which hides "Registrar incidencia"
     * when this is false instead of letting an ineligible role fill the
     * form only to get a 403).
     */
    registrationEligibility: async () => {
      const { data } = await apiClient.get<{ data: { can_register: boolean } }>(
        `${basePath}/registration-eligibility`
      )
      return data.data
    },
  }),
})
