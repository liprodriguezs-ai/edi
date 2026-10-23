/**
 * The detail screen renders twice: once while the screening is loading, once with
 * it. Any hook declared AFTER the loading early-return runs only on the second
 * pass, which React rejects with "Rendered more hooks than during the previous
 * render" and the whole route falls into the error boundary.
 *
 * This shipped once already — past typecheck and the whole suite — because
 * nothing rendered this component through that transition.
 */
import { ScreeningDetail } from '@/modules/screening/ScreeningDetail'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

const SCREENING = {
  id: 's-1',
  visit_id: 'v-1',
  child_id: 'c-1',
  chronological_age_days: 570,
  screening_status: { id: 'st-2', key: 'completed', name: 'Completado' },
  result_color: { id: 'rc-1', key: 'green', name: 'Verde' },
  visit: { id: 'v-1', receipt_number: 'V-2026-000001' },
  area_results: [],
  findings: [],
  score_runs: [],
}

describe('ScreeningDetail — hook order across the loading transition', () => {
  beforeEach(() => {
    // Resolves on a later tick, so the component renders its loading state first
    // and the loaded state second — the exact sequence that surfaced the bug.
    vi.spyOn(apiClient, 'get').mockImplementation(
      () => new Promise((resolve) => setTimeout(() => resolve({ data: { data: SCREENING } }), 0))
    )
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders through loading and then the loaded screening without a hook-order error', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ScreeningDetail screeningId="s-1" />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('Descargar documento')).toBeInTheDocument()
    })
  })
})
