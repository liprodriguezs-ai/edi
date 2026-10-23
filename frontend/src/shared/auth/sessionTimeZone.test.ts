import type { SessionProfile } from '@/shared/auth/Session'
import { sessionStore } from '@/shared/auth/sessionStore'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { displayFormatDate, getTenantTimeZone } from '@/shared/utils/dateHelper'
import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/firebase/fcmRuntime', () => ({ resetFcmGlobals: vi.fn() }))

const BROWSER_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone

function makeProfile(timezone?: string | null): SessionProfile {
  return {
    user: { id: 'u-1', email: 'ana@test.mx', name: 'Ana' },
    abilities: [],
    roles: [],
    tenant: { id: 't-1', name: 'Centro', tenant_payment_type_id: null, timezone },
  }
}

describe('zona horaria de la sesión', () => {
  afterEach(() => {
    sessionStore.getState().clearSession()
    localStorage.clear()
  })

  it('setSession fija la zona del tenant para los helpers de fecha', () => {
    sessionStore.getState().setSession({ profile: makeProfile('Asia/Tokyo'), token: 'tok' })

    expect(getTenantTimeZone()).toBe('Asia/Tokyo')
    expect(displayFormatDate('2026-10-21T03:00:00Z')).toBe('oct 21, 2026 12:00')
  })

  it('setProfile cambia la zona y clearSession vuelve a la del navegador', () => {
    sessionStore.getState().setSession({ profile: makeProfile('Asia/Tokyo'), token: 'tok' })
    sessionStore.getState().setProfile(makeProfile('America/Mexico_City'))
    expect(getTenantTimeZone()).toBe('America/Mexico_City')

    sessionStore.getState().clearSession()
    expect(getTenantTimeZone()).toBe(BROWSER_ZONE)
  })

  it('restoreSession restaura la zona persistida', () => {
    sessionStore.getState().setSession({ profile: makeProfile('Pacific/Honolulu'), token: 'tok' })
    // Simula recarga: el estado de módulo se pierde pero el perfil sigue en storage.
    sessionStore.setState({ profile: null })
    sessionStore.getState().restoreSession()

    expect(getTenantTimeZone()).toBe('Pacific/Honolulu')
  })

  it('una zona faltante o inválida cae a la del navegador', () => {
    sessionStore.getState().setSession({ profile: makeProfile('Mars/Olympus'), token: 'tok' })
    expect(getTenantTimeZone()).toBe(BROWSER_ZONE)

    sessionStore.getState().setSession({ profile: makeProfile(null), token: 'tok' })
    expect(getTenantTimeZone()).toBe(BROWSER_ZONE)
  })

  it('useTenantTimeZone reacciona a los cambios de sesión', () => {
    const { result } = renderHook(() => useTenantTimeZone())
    expect(result.current).toBe(BROWSER_ZONE)

    act(() => {
      sessionStore.getState().setSession({ profile: makeProfile('Asia/Tokyo'), token: 'tok' })
    })
    expect(result.current).toBe('Asia/Tokyo')
  })
})
