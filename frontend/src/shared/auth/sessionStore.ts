import type { SessionProfile } from '@/shared/auth/Session'
import { resetFcmGlobals } from '@/shared/firebase/fcmRuntime'
import { setActiveCurrency } from '@/shared/utils/currency'
import { setTenantTimeZone } from '@/shared/utils/dateHelper'
import { createStore } from 'zustand/vanilla'

const STORAGE_KEY = 'session'

/** Only profile is persisted (no tokens). Tokens live in memory only; refresh uses httpOnly cookie. */
interface PersistedSession {
  profile: SessionProfile | null
}

interface SessionState {
  profile: SessionProfile | null
  token: string | null
  refreshToken: string | null
  isAuthenticated: boolean
  isRestoring: boolean
  setSession: (_payload: { profile: SessionProfile; token: string }) => void
  setTokens: (_payload: { token: string }) => void
  setProfile: (_profile: SessionProfile) => void
  clearSession: () => void
  restoreSession: () => void
  logout: (_options?: { propagate?: boolean }) => void
}

const readPersistedState = (): PersistedSession => {
  if (typeof window === 'undefined') {
    return { profile: null }
  }

  try {
    const raw = localStorage.getItem(`fallback_${STORAGE_KEY}`)
    if (raw) {
      const parsed = JSON.parse(raw) as PersistedSession
      return { profile: parsed.profile ?? null }
    }

    const secureData = localStorage.getItem(`secure_${STORAGE_KEY}`)
    if (secureData) {
      const parsed = JSON.parse(secureData) as PersistedSession
      return { profile: parsed.profile ?? null }
    }
    return { profile: null }
  } catch (error) {
    console.warn('Failed to read session from storage', error)
    return { profile: null }
  }
}

const persistState = (state: PersistedSession) => {
  if (typeof window === 'undefined') {
    return
  }

  try {
    localStorage.setItem(`secure_${STORAGE_KEY}`, JSON.stringify(state))
  } catch (error) {
    console.error('Failed to persist session state', error)
  }
}

const clearPersistedState = () => {
  if (typeof window === 'undefined') {
    return
  }

  try {
    localStorage.removeItem(`secure_${STORAGE_KEY}`)
    localStorage.removeItem(`fallback_${STORAGE_KEY}`)
  } catch (error) {
    console.error('Failed to clear persisted state', error)
  }
}

export const sessionStore = createStore<SessionState>((set) => ({
  profile: null,
  token: null,
  refreshToken: null,
  isAuthenticated: false,
  isRestoring: true,
  setSession: ({ profile, token }) => {
    persistState({ profile })
    setActiveCurrency(profile?.tenant?.currency)
    setTenantTimeZone(profile?.tenant?.timezone)
    set({
      profile,
      token,
      refreshToken: null,
      isAuthenticated: true,
      isRestoring: false,
    })
  },
  setTokens: ({ token }) => {
    set((_current) => ({
      token,
      refreshToken: null,
      isAuthenticated: Boolean(token),
    }))
  },
  setProfile: (profile) => {
    set((current) => {
      persistState({ profile })
      setActiveCurrency(profile?.tenant?.currency)
      setTenantTimeZone(profile?.tenant?.timezone)
      return {
        profile,
        token: current.token,
        refreshToken: current.refreshToken,
        isAuthenticated: Boolean(current.token),
        isRestoring: false,
      }
    })
  },
  clearSession: () => {
    clearPersistedState()
    setActiveCurrency(null)
    setTenantTimeZone(null)
    set({
      profile: null,
      token: null,
      refreshToken: null,
      isAuthenticated: false,
      isRestoring: false,
    })
  },
  restoreSession: () => {
    const persisted = readPersistedState()
    setActiveCurrency(persisted.profile?.tenant?.currency)
    setTenantTimeZone(persisted.profile?.tenant?.timezone)

    set({
      profile: persisted.profile,
      token: null,
      refreshToken: null,
      isAuthenticated: false,
      isRestoring: Boolean(persisted.profile),
    })
  },
  logout: ({ propagate } = { propagate: true }) => {
    // Tear down the FCM singleton (listener + token/init state). Essential for the
    // in-SPA logout path (401 interceptor → propagate:false): without it, the
    // previous user's onMessage listener survives into the next session. Harmless
    // no-op for the propagate:true full-reload path.
    resetFcmGlobals()
    clearPersistedState()
    setTenantTimeZone(null)
    set({
      profile: null,
      token: null,
      refreshToken: null,
      isAuthenticated: false,
      isRestoring: false,
    })
    if (propagate && typeof window !== 'undefined') {
      window.location.href = '/login'
    }
  },
}))
