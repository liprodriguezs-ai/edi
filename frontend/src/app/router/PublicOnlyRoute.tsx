import { sessionStore } from '@/shared/auth/sessionStore'
import { Navigate, Outlet } from 'react-router-dom'
import { useStore } from 'zustand'

export function PublicOnlyRoute() {
  const isAuthenticated = useStore(sessionStore, (s) => s.isAuthenticated)
  const isRestoring = useStore(sessionStore, (s) => s.isRestoring)

  if (isRestoring) {
    return null
  }

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
