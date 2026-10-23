import { homeDestination } from '@/app/router/homeDestination'
import { useOnboardingChecklist } from '@/modules/onboarding/hooks/useOnboardingChecklist'
import { sessionStore } from '@/shared/auth/sessionStore'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useStore } from 'zustand'
import { Navigate } from 'react-router-dom'

const NO_ROLES: string[] = []

/**
 * Smart home redirect:
 * - Admin users with incomplete onboarding → /onboarding
 * - Clinical staff (can read the agenda) → today's agenda
 * - Everyone else → their roleRoute (or /dashboard as fallback)
 */
export function HomeRedirect() {
  const roles = useStore(sessionStore, (s) => s.profile?.roles ?? NO_ROLES)
  const canReadAgenda = usePermissions('read.appointment')

  const { checklist, isLoading, isAdmin } = useOnboardingChecklist()

  if (isAdmin && isLoading) {
    return null
  }

  if (isAdmin && checklist && !checklist.is_complete) {
    return <Navigate to="/onboarding" replace />
  }

  return <Navigate to={homeDestination(roles, canReadAgenda)} replace />
}
