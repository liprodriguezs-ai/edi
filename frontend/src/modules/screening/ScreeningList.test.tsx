/**
 * REQ-SUI-002 scenario — filters must NOT fire POST /selects on mount
 * (lazy catalogs, react skill §2.12).
 */
import { ScreeningList } from '@/modules/screening/ScreeningList'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

describe('ScreeningList — filter laziness', () => {
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
          <ScreeningList />
        </MemoryRouter>
      </QueryClientProvider>
    )

    // Let the initial list fetch (POST /screening/search) settle.
    await waitFor(() => expect(apiClient.post).toHaveBeenCalled())

    const selectsCalls = vi
      .mocked(apiClient.post)
      .mock.calls.filter(([url]) => typeof url === 'string' && url.includes('/selects'))
    expect(selectsCalls).toHaveLength(0)
  })
})
