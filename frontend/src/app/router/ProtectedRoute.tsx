import { sessionStore } from '@/shared/auth/sessionStore'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useStore } from 'zustand'

export function ProtectedRoute() {
  const isAuthenticated = useStore(sessionStore, (s) => s.isAuthenticated)
  const isRestoring = useStore(sessionStore, (s) => s.isRestoring)
  const location = useLocation()

  if (isRestoring) {
    return null
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  return <Outlet />
}
