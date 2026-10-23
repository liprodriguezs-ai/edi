/**
 * Smoke test — the detail view renders the child name and status once the
 * mocked GET /follow_up/:id resolves.
 */
import { FollowUpDetail } from '@/modules/followUp/FollowUpDetail'
import type { IFollowUp } from '@/modules/followUp/schema'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

const FOLLOW_UP: IFollowUp = {
  id: 'fu-1',
  child_id: 'child-1',
  screening_id: 'screening-1',
  follow_up_type_id: 'type-1',
  follow_up_status_id: 'status-1',
  due_date: '2026-09-01',
  child: { id: 'child-1', first_name: 'Ana', last_name_paternal: 'Pérez' },
  follow_up_type: { id: 'type-1', key: 'reassess_3_months', name: 'Reaplicación a 3 meses' },
  follow_up_status: { id: 'status-1', key: 'pending', name: 'Pendiente' },
}

describe('FollowUpDetail — smoke', () => {
  beforeEach(() => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: FOLLOW_UP } })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the child name and the follow-up status once loaded', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <FollowUpDetail followUpId="fu-1" />
        </MemoryRouter>
      </QueryClientProvider>
    )

    expect(await screen.findByText('Ana Pérez')).toBeInTheDocument()
    expect(screen.getByText('Pendiente')).toBeInTheDocument()
    expect(screen.getByText('Reaplicación a 3 meses')).toBeInTheDocument()
  })
})
