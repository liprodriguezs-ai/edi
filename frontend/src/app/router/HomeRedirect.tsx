import { useOnboardingChecklist } from '@/modules/onboarding/hooks/useOnboardingChecklist'
import { sessionStore } from '@/shared/auth/sessionStore'
import { ERoleUserSlug, roleRoutes } from '@/shared/interfaces/Entity'
import { useStore } from 'zustand'
import { Navigate } from 'react-router-dom'

/**
 * Smart home redirect:
 * - Admin users with incomplete onboarding → /onboarding
 * - Everyone else → their roleRoute (or /dashboard as fallback)
 */
export function HomeRedirect() {
  const profile = useStore(sessionStore, (s) => s.profile)
  const roles: string[] = profile?.roles ?? []

  const { checklist, isLoading, isAdmin } = useOnboardingChecklist()

  if (isAdmin && isLoading) {
    return null
  }

  if (isAdmin && checklist && !checklist.is_complete) {
    return <Navigate to="/onboarding" replace />
  }

  // Use `roles.some` via the hook's isAdmin, but still need first matching role for destination
  const role = (roles.find((r) =>
    Object.values(ERoleUserSlug).includes(r as ERoleUserSlug)
  ) ?? null) as ERoleUserSlug | null

  const destination = role ? (roleRoutes[role] ?? '/dashboard') : '/dashboard'
  return <Navigate to={destination} replace />
}
