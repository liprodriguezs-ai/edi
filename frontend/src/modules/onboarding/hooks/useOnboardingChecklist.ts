import type { IOnboardingChecklist } from '@/modules/onboarding/schema'
import { OnboardingResource } from '@/modules/onboarding/services/OnboardingResource'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useRoles } from '@/shared/hooks/useRoles'
import { useQuery } from '@tanstack/react-query'

const ADMIN_ROLES: ERoleUserSlug[] = [ERoleUserSlug.ADMINISTRATOR, ERoleUserSlug.ACCOUNT_OWNER]

export const ONBOARDING_CHECKLIST_QUERY_KEY = ['onboarding-checklist'] as const

async function fetchChecklist(): Promise<IOnboardingChecklist> {
  return OnboardingResource.checklist()
}

export interface UseOnboardingChecklistResult {
  checklist: IOnboardingChecklist | null
  isLoading: boolean
  isComplete: boolean
  progress: number
  isAdmin: boolean
}

export function useOnboardingChecklist(): UseOnboardingChecklistResult {
  const { hasAnyRole } = useRoles()
  const isAdmin = hasAnyRole(ADMIN_ROLES)

  const query = useQuery({
    queryKey: ONBOARDING_CHECKLIST_QUERY_KEY,
    queryFn: fetchChecklist,
    enabled: isAdmin,
    // El avance depende de datos creados en otros módulos (torres, unidades,
    // residentes, etc.). Refetcheamos al volver al dashboard o al reenfocar la
    // ventana para que el progreso refleje lo recién registrado, no el caché.
    staleTime: 30 * 1000,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  return {
    checklist: query.data ?? null,
    isLoading: query.isLoading,
    isComplete: query.data?.is_complete ?? false,
    progress: query.data?.progress_percentage ?? 0,
    isAdmin,
  }
}
