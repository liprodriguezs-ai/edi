/**
 * REQ-FUP-009 scenario — filters must NOT fire POST /selects on mount
 * (lazy catalogs, react skill §2.12).
 */
import { FollowUpList } from '@/modules/followUp/FollowUpList'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { setTenantTimeZone } from '@/shared/utils/dateHelper'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

describe('FollowUpList — filter laziness', () => {
  beforeEach(() => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [], meta: null } })
    vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: [], meta: null } })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('does not call POST /selects when the list mounts', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <FollowUpList />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => expect(apiClient.post).toHaveBeenCalled())

    const selectsCalls = vi
      .mocked(apiClient.post)
      .mock.calls.filter(([url]) => typeof url === 'string' && url.includes('/selects'))
    expect(selectsCalls).toHaveLength(0)
  })

  it('"Próximos 7 días" usa días civiles del tenant', async () => {
    // 1 nov 03:00 UTC = 1 nov 12:00 en Tokio (la zona del navegador diría otro día).
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-01T03:00:00Z'))
    setTenantTimeZone('Asia/Tokyo')
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    try {
      render(
        <QueryClientProvider client={queryClient}>
          <MemoryRouter>
            <FollowUpList />
          </MemoryRouter>
        </QueryClientProvider>
      )

      fireEvent.click(screen.getByRole('button', { name: 'Próximos 7 días' }))

      await waitFor(() => {
        const bodies = vi.mocked(apiClient.post).mock.calls.map((call) => JSON.stringify(call))
        expect(bodies.some((body) => body.includes('["2026-11-01","2026-11-08"]'))).toBe(true)
      })
    } finally {
      setTenantTimeZone(null)
      vi.useRealTimers()
    }
  })
})
