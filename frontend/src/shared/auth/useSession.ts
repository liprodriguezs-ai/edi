import { randomId } from '@/shared/utils/randomId'
import { grantAnalyticsConsent } from '@/shared/analytics/consent'
import { loadSnippets } from '@/shared/analytics/loadSnippets'
import { trackEvent } from '@/shared/analytics/trackEvent'
import { queryClient } from '@/shared/api/queryClient'
import {
  fetchSessionProfile,
  type LoginRequest,
  loginRequest,
  type RegisterRequest,
  refreshTokenRequest,
  registerRequest,
} from '@/shared/auth/authService'
import { sessionStore } from '@/shared/auth/sessionStore'
import { useCallback } from 'react'
import { useStore } from 'zustand'

export const useSession = () => {
  const profile = useStore(sessionStore, (state) => state.profile)
  const token = useStore(sessionStore, (state) => state.token)
  const isAuthenticated = useStore(sessionStore, (state) => state.isAuthenticated)
  const isRestoring = useStore(sessionStore, (state) => state.isRestoring)

  const login = useCallback(async (credentials: LoginRequest) => {
    const result = await loginRequest(credentials)
    sessionStore.getState().setSession(result)
    // Aceptar el Aviso de Privacidad en login cuenta como opt-in de analytics
    // (ver consent.ts) — recargamos los snippets por si venían sin consentimiento.
    grantAnalyticsConsent()
    loadSnippets()
    return result.profile
  }, [])

  const register = useCallback(async (payload: RegisterRequest) => {
    // Se genera ACÁ (no en el backend) para poder reusarlo en el CompleteRegistration
    // del navegador — el servidor recibe el mismo id y dispara su propio evento de
    // respaldo con él, así ambos deduplican en vez de contarse dos veces.
    const registrationEventId = randomId()
    const result = await registerRequest({ ...payload, event_id: registrationEventId })
    sessionStore.getState().setSession(result)
    // Aceptar el Aviso de Privacidad en signup cuenta como opt-in de analytics
    // (ver consent.ts) — hay que otorgarlo ANTES de trackEvent para que dispare.
    grantAnalyticsConsent()
    loadSnippets()
    // Fire-and-forget: cubre el registro de la app (incl. signup-form). El
    // servidor dispara el mismo evento como respaldo por si el navegador falla.
    void trackEvent('CompleteRegistration', {}, {
      email: result.profile.user.email,
      external_id: result.profile.user.id,
    }, registrationEventId)
    // StartTrial YA NO se dispara acá: el registro no otorga ningún trial local
    // (el organización nace bloqueado). Dispararlo aquí contaba como "inicio de
    // prueba" a cualquiera que abandonara antes de pagar. Ahora se dispara en
    // SelectPlanPage/SuccessView, al confirmarse el checkout de Stripe que
    // efectivamente arrancó el trial.
    return result.profile
  }, [])

  const logout = useCallback(() => {
    queryClient.clear()
    sessionStore.getState().logout()
  }, [])

  const restoreSession = useCallback(() => {
    sessionStore.getState().restoreSession()
  }, [])

  const loadProfile = useCallback(async () => {
    try {
      const profileResponse = await fetchSessionProfile()
      sessionStore.getState().setProfile(profileResponse)
      return profileResponse
    } catch (error) {
      queryClient.clear()
      sessionStore.getState().logout()
      throw error
    }
  }, [])

  const validateSession = useCallback(async () => {
    const currentState = sessionStore.getState()

    if (!currentState.token) {
      try {
        const { access_token } = await refreshTokenRequest()
        sessionStore.getState().setTokens({ token: access_token })
        const profileResponse = await fetchSessionProfile()
        sessionStore.getState().setProfile(profileResponse)
        return profileResponse
      } catch {
        queryClient.clear()
        sessionStore.getState().logout()
        return
      }
    }

    try {
      const profileResponse = await fetchSessionProfile()
      sessionStore.getState().setProfile(profileResponse)
      return profileResponse
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : 'Unknown error'
      console.warn('Token validation failed:', errorMessage)
      queryClient.clear()
      sessionStore.getState().logout()
      throw error
    }
  }, [])

  return {
    profile,
    token,
    user: profile?.user ?? null,
    abilities: profile?.abilities ?? [],
    roles: profile?.roles ?? [],
    company: profile?.company,
    isAuthenticated,
    isRestoring,
    login,
    register,
    logout,
    restoreSession,
    loadProfile,
    validateSession,
    role: profile?.roles[0] ?? null,
  }
}
