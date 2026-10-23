/**
 * REQ-RPT-011 — "Exportar a Excel" carries the active filters through
 * `useBaseList().onReport()` (POST /customReport with the current Orion
 * filters + `_model=Screening`).
 */
import { NominalList } from '@/modules/reports/NominalList'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
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

describe('NominalList — export honors active filters', () => {
  beforeEach(() => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: new Blob(['x']) })
    vi.spyOn(apiClient, 'post').mockImplementation((url: string) => {
      if (url.includes('/customReport')) {
        return Promise.resolve({ data: new Blob(['xlsx-bytes']) })
      }
      return Promise.resolve({ data: { data: [], meta: null } })
    })
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:mock')
    globalThis.URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('calls /customReport with _model=Screening on export click', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <NominalList />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => expect(apiClient.post).toHaveBeenCalled())

    const exportButton = screen.getByRole('button', { name: /exportar a excel/i })
    fireEvent.click(exportButton)

    await waitFor(() => {
      const call = vi
        .mocked(apiClient.post)
        .mock.calls.find(([url]) => typeof url === 'string' && url.includes('/customReport'))
      expect(call).toBeDefined()
      expect(call?.[1]).toMatchObject({ _model: 'Screening', _type: 'xlsx' })
    })
  })
})
