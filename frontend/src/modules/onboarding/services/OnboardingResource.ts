import { apiClient } from '@/shared/api/apiClient'
import type { IOnboardingChecklist } from '@/modules/onboarding/schema'

export const OnboardingResource = {
  checklist: async (): Promise<IOnboardingChecklist> => {
    const { data } = await apiClient.get<{ data: IOnboardingChecklist }>(
      '/dashboard/onboarding-checklist',
    )
    return data.data
  },
}
