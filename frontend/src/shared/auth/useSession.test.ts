/**
 * Tests for useSession.register — StartTrial analytics timing (D7/D2).
 *
 * Registration no longer grants any local trial (the organization is born
 * blocked), so firing StartTrial there would count an abandoned registration
 * as a trial start. CompleteRegistration must still fire (the registration
 * itself did happen); StartTrial now fires only at checkout completion
 * (SelectPlanPage's SuccessView).
 */
import { grantAnalyticsConsent } from '@/shared/analytics/consent'
import { loadSnippets } from '@/shared/analytics/loadSnippets'
import { trackEvent } from '@/shared/analytics/trackEvent'
import { registerRequest } from '@/shared/auth/authService'
import type { SessionProfile } from '@/shared/auth/Session'
import { useSession } from '@/shared/auth/useSession'
import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/auth/authService', () => ({
  registerRequest: vi.fn(),
}))
vi.mock('@/shared/analytics/consent', () => ({ grantAnalyticsConsent: vi.fn() }))
vi.mock('@/shared/analytics/loadSnippets', () => ({ loadSnippets: vi.fn() }))
vi.mock('@/shared/analytics/trackEvent', () => ({
  trackEvent: vi.fn(() => Promise.resolve('evt-1')),
}))

const mockRegisterRequest = vi.mocked(registerRequest)
const mockTrackEvent = vi.mocked(trackEvent)
const mockGrantConsent = vi.mocked(grantAnalyticsConsent)
const mockLoadSnippets = vi.mocked(loadSnippets)

function makeProfile(): SessionProfile {
  return {
    user: { id: 'u-1', email: 'ana@test.mx', name: 'Ana' },
    abilities: [],
    roles: [],
  }
}

describe('useSession.register — StartTrial timing (D7)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockRegisterRequest.mockResolvedValue({ profile: makeProfile(), token: 'tok-1' })
  })

  it('fires CompleteRegistration but NEVER StartTrial at registration', async () => {
    const { result } = renderHook(() => useSession())

    await act(async () => {
      await result.current.register({
        name: 'Ana',
        organization_name: 'Las Palmas',
        email: 'ana@test.mx',
        password: 'Secret123',
        password_confirmation: 'Secret123',
      })
    })

    const eventNames = mockTrackEvent.mock.calls.map((call) => call[0])
    expect(eventNames).toContain('CompleteRegistration')
    expect(eventNames).not.toContain('StartTrial')
    // Analytics consent/snippets still load — registration is real, only the
    // "trial started" claim moved to checkout completion.
    expect(mockGrantConsent).toHaveBeenCalledOnce()
    expect(mockLoadSnippets).toHaveBeenCalledOnce()
  })

  it('an abandoned registration (the request itself fails) fires no event at all', async () => {
    mockRegisterRequest.mockRejectedValueOnce(new Error('network error'))
    const { result } = renderHook(() => useSession())

    await expect(
      act(async () => {
        await result.current.register({
          name: 'Ana',
          organization_name: 'Las Palmas',
          email: 'ana@test.mx',
          password: 'Secret123',
          password_confirmation: 'Secret123',
        })
      })
    ).rejects.toThrow()

    expect(mockTrackEvent).not.toHaveBeenCalled()
  })
})
