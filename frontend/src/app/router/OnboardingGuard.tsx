import { useOnboardingChecklist } from '@/modules/onboarding/hooks/useOnboardingChecklist'
import { Navigate, Outlet } from 'react-router-dom'

/**
 * Keeps the dashboard hidden until the cluster setup is finished.
 *
 * Onboarding lives on its own page (`/onboarding`): while an administrator
 * still has required steps pending, any attempt to reach the dashboard is
 * redirected there. Non-admin roles and completed clusters pass through.
 */
export function OnboardingGuard() {
  const { checklist, isLoading, isAdmin, isComplete } = useOnboardingChecklist()

  if (!isAdmin) {
    return <Outlet />
  }

  if (isLoading) {
    return null
  }

  if (checklist && !isComplete) {
    return <Navigate to='/onboarding' replace />
  }

  return <Outlet />
}
