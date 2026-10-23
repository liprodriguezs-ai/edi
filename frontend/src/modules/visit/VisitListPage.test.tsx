/**
 * odd/tasks/edi-agenda.md S11 — "Prueba EDI" in the desktop table must open
 * the capture, not also fire the row click that navigates to the child.
 */
import { VisitListPage } from '@/modules/visit/VisitListPage'
import { apiClient } from '@/shared/api/apiClient'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

const VISIT = {
  id: 'visit-1',
  child_id: 'child-1',
  health_center_id: 'hc-1',
  practitioner_id: 'pr-1',
  receipt_number: 'V-2026-000001',
  visit_date: '2026-10-20',
  child: { id: 'child-1', first_name: 'Ana', last_name_paternal: 'López' },
}

describe('VisitListPage — row action', () => {
  beforeEach(() => {
    vi.spyOn(apiClient, 'post').mockResolvedValue({
      data: { data: [VISIT], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } },
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('opens the screening capture without navigating to the child', async () => {
    render(
      <MemoryRouter initialEntries={['/visit']}>
        <Routes>
          <Route path='/visit' element={<VisitListPage />} />
          <Route path='/screening/apply/:visitId' element={<p>captura del tamiz</p>} />
          <Route path='/child/:id' element={<p>expediente del niño</p>} />
        </Routes>
      </MemoryRouter>
    )

    const [tableButton] = await screen.findAllByRole('button', { name: /^Prueba EDI$/ })
    fireEvent.click(tableButton)

    expect(await screen.findByText('captura del tamiz')).toBeInTheDocument()
    expect(screen.queryByText('expediente del niño')).not.toBeInTheDocument()
  })

  it('"A elección" asks for the group to apply without navigating to the child', async () => {
    render(
      <MemoryRouter initialEntries={['/visit']}>
        <Routes>
          <Route path='/visit' element={<VisitListPage />} />
          <Route path='/child/:id' element={<p>expediente del niño</p>} />
        </Routes>
      </MemoryRouter>
    )

    const [chosenButton] = await screen.findAllByRole('button', { name: /^A elección$/ })
    fireEvent.click(chosenButton)

    expect(await screen.findByRole('dialog', { name: 'Aplicar Prueba EDI' })).toBeInTheDocument()
    expect(screen.getByText('Evaluación a realizar')).toBeInTheDocument()
    expect(screen.queryByText('expediente del niño')).not.toBeInTheDocument()
  })

  it('still opens the child record when the row itself is clicked', async () => {
    render(
      <MemoryRouter initialEntries={['/visit']}>
        <Routes>
          <Route path='/visit' element={<VisitListPage />} />
          <Route path='/child/:id' element={<p>expediente del niño</p>} />
        </Routes>
      </MemoryRouter>
    )

    const [folioCell] = await screen.findAllByText('V-2026-000001')
    fireEvent.click(folioCell)

    expect(await screen.findByText('expediente del niño')).toBeInTheDocument()
  })
})
