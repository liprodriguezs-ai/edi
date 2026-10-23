/**
 * REQ-RPT-010 — dashboard renders fixture aggregates; mounting must NOT
 * fire POST /selects (lazy catalogs, react skill §2.12).
 */
import { IndicatorDashboardPage } from '@/modules/reports/IndicatorDashboardPage'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = {
  profile: { abilities: ['*'], tenant: { timezone: 'America/Mexico_City' } },
}

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

const MOCK_SUMMARY = {
  period: '2026-08',
  health_center_id: null,
  screenings_applied: 12,
  screenings_completed: 10,
  children_screened: 9,
  result_green_count: 7,
  result_yellow_count: 3,
  result_red_count: 2,
  percent_green: 58,
  percent_yellow: 25,
  percent_red: 17,
  first_time_count: 5,
  alert_sign_screening_count: 2,
  risk_factor_screening_count: 1,
  confirmatory_pending_count: 1,
  situation_breakdown: { first_time_green: 4, first_time_red: 1 },
}

describe('IndicatorDashboardPage', () => {
  beforeEach(() => {
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => {
      if (url.includes('/reports/indicators/summary')) {
        return Promise.resolve({ data: { data: MOCK_SUMMARY } })
      }
      if (url.includes('/reports/indicators/monthly-series')) {
        return Promise.resolve({
          data: {
            data: [
              {
                period: '2026-08',
                screenings_applied: 12,
                result_green_count: 7,
                result_yellow_count: 3,
                result_red_count: 2,
              },
            ],
          },
        })
      }
      if (url.includes('/reports/indicators/area-breakdown')) {
        return Promise.resolve({
          data: {
            data: {
              period: '2026-08',
              health_center_id: null,
              areas: { MG: { green: 5, yellow: 2, red: 1 } },
            },
          },
        })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: [] } })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders fixture aggregates and never calls POST /selects on mount', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <IndicatorDashboardPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => expect(screen.getByText('12')).toBeInTheDocument())
    expect(screen.getByText('9')).toBeInTheDocument()

    const selectsCalls = vi
      .mocked(apiClient.post)
      .mock.calls.filter(([url]) => typeof url === 'string' && url.includes('/selects'))
    expect(selectsCalls).toHaveLength(0)
  })

  it('pide el periodo del mes actual del tenant, no el mes UTC', async () => {
    // 1 nov 03:00 UTC = 31 oct 21:00 en Ciudad de México.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-01T03:00:00Z'))
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    try {
      render(
        <QueryClientProvider client={queryClient}>
          <MemoryRouter>
            <IndicatorDashboardPage />
          </MemoryRouter>
        </QueryClientProvider>
      )

      await waitFor(() => {
        const summaryCall = vi
          .mocked(apiClient.get)
          .mock.calls.find(
            ([url]) => typeof url === 'string' && url.includes('/reports/indicators/summary')
          )
        expect(JSON.stringify(summaryCall)).toContain('2026-10')
      })
    } finally {
      vi.useRealTimers()
    }
  })
})
