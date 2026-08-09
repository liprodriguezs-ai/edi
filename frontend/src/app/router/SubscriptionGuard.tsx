import { sessionStore } from '@/shared/auth/sessionStore'
import { Navigate, Outlet } from 'react-router-dom'
import { useStore } from 'zustand'

export function SubscriptionGuard() {
  const isBlocked = useStore(sessionStore, (s) => s.profile?.subscription?.is_blocked)

  if (isBlocked) {
    return <Navigate to="/select-plan" replace />
  }

  return <Outlet />
}
