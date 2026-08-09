import type {
  IEmailCheckResult,
  IResidentInvitation,
  InviteFormValues,
} from '@/modules/residentInvitation/schema'
import { apiClient } from '@/shared/api/apiClient'

export interface InviteResult {
  invitation: IResidentInvitation
  warnings: string[]
}

/**
 * Resident invitation endpoints. These are custom (non-Orion) routes, so they
 * live here in the resource layer instead of createCrudApi. The plaintext token
 * is never returned by the backend.
 */
export const ResidentInvitationResource = {
  async checkEmail(email: string, unitId: string): Promise<IEmailCheckResult> {
    const { data } = await apiClient.post<{ data: IEmailCheckResult }>(
      '/resident-invitation/check-email',
      { email, unit_id: unitId }
    )
    return data.data
  },

  async invite(unitId: string, values: InviteFormValues): Promise<InviteResult> {
    const { data } = await apiClient.post<{
      data: IResidentInvitation
      warnings?: string[]
    }>('/resident-invitation', {
      email: values.email,
      unit_id: unitId,
      unit_relationship_id: values.unit_relationship_id,
    })
    return { invitation: data.data, warnings: data.warnings ?? [] }
  },

  async listByUnit(unitId: string): Promise<IResidentInvitation[]> {
    const { data } = await apiClient.get<{ data: IResidentInvitation[] }>(
      `/unit/${unitId}/invitations`
    )
    return data.data
  },

  async resend(id: string): Promise<IResidentInvitation> {
    const { data } = await apiClient.post<{ data: IResidentInvitation }>(
      `/resident-invitation/${id}/resend`
    )
    return data.data
  },

  async revoke(id: string): Promise<string> {
    await apiClient.delete(`/resident-invitation/${id}`)
    return id
  },

  async deactivateMembership(unitUserId: string): Promise<void> {
    await apiClient.post(`/unit-membership/${unitUserId}/deactivate`)
  },

  async blockResident(userId: string, reason: string): Promise<void> {
    await apiClient.post(`/resident/${userId}/block`, { reason })
  },
}
