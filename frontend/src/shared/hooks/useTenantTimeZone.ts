import { sessionStore } from '@/shared/auth/sessionStore'
import { resolveTenantTimeZone } from '@/shared/utils/tenantTime'
import { useStore } from 'zustand'

/**
 * Zona horaria IANA del tenant de la sesión (fallback: la del navegador).
 * Se suscribe al perfil, así que re-renderiza al restaurar, cambiar o cerrar sesión.
 */
export function useTenantTimeZone(): string {
  const timeZone = useStore(sessionStore, (state) => state.profile?.tenant?.timezone)
  return resolveTenantTimeZone(timeZone)
}
