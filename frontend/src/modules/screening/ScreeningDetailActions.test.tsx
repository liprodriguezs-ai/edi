/**
 * The detail screen offers ONE main action per state and keeps the audit
 * actions ("Verificar cálculo", "Anular prueba") in "Más acciones", only where
 * the server accepts them.
 */
import { ScreeningDetail } from '@/modules/screening/ScreeningDetail'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

function screeningWithStatus(key: string) {
  return {
    id: 's-1',
    visit_id: 'v-1',
    child_id: 'c-1',
    chronological_age_days: 570,
    screening_status: { id: `st-${key}`, key, name: key },
    result_color: key === 'draft' ? null : { id: 'rc-1', key: 'green', name: 'Verde' },
    visit: { id: 'v-1', receipt_number: 'V-2026-000001' },
    child: { id: 'c-1', first_name: 'Ana', last_name_paternal: 'López', last_name_maternal: null },
    area_results: [],
    findings: [],
    score_runs: [],
  }
}

function renderDetail(statusKey: string) {
  vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: screeningWithStatus(statusKey) } })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ScreeningDetail screeningId='s-1' />
      </MemoryRouter>
    </QueryClientProvider>
  )
}

async function openMoreActions() {
  const trigger = await screen.findByRole('button', { name: 'Más acciones' })
  fireEvent.keyDown(trigger, { key: 'Enter' })
}

describe('ScreeningDetail — actions by state', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('draft: continues the capture and offers no audit recalculation', async () => {
    renderDetail('draft')

    expect(await screen.findByRole('button', { name: 'Continuar captura' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Cerrar prueba/ })).not.toBeInTheDocument()

    await openMoreActions()
    expect(await screen.findByRole('menuitem', { name: /Anular prueba/ })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: /Verificar cálculo/ })).not.toBeInTheDocument()
  })

  it('scored: the main action closes the test and schedules the next appointment', async () => {
    renderDetail('scored')

    expect(await screen.findByRole('button', { name: 'Cerrar prueba y agendar cita' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Corregir respuestas' })).toBeInTheDocument()

    await openMoreActions()
    expect(await screen.findByRole('menuitem', { name: /Verificar cálculo/ })).toBeInTheDocument()
  })

  it('scored: correcting answers warns that the test must be scored again', async () => {
    renderDetail('scored')

    fireEvent.click(await screen.findByRole('button', { name: 'Corregir respuestas' }))

    await waitFor(() => {
      expect(screen.getByText(/tendrás que calificarla de nuevo/)).toBeInTheDocument()
    })
  })

  it('completed: no capture actions, the next appointment stays available', async () => {
    renderDetail('completed')

    expect(await screen.findByRole('button', { name: /Agendar próxima cita/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Continuar captura' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Cerrar prueba/ })).not.toBeInTheDocument()
  })

  it('invalidated: no actions besides reading it', async () => {
    renderDetail('invalidated')

    await screen.findByText('Anulada')
    expect(screen.queryByRole('button', { name: 'Más acciones' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Continuar captura' })).not.toBeInTheDocument()
  })

  it('goes back to the tests of the child record', async () => {
    renderDetail('completed')

    expect(await screen.findByRole('link', { name: /Volver al expediente/ })).toHaveAttribute(
      'href',
      '/child/c-1?tab=pruebas'
    )
  })
})
