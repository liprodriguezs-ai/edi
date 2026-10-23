import { ChildScreeningsPanel } from '@/modules/screening/components/ChildScreeningsPanel'
import type { IScreening } from '@/modules/screening/schema'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

function screening(id: string, date: string, status: string, color: string | null, days: number): IScreening {
  return {
    id,
    child_id: 'c-1',
    chronological_age_days: days,
    screening_status: { id: `st-${status}`, key: status, name: status },
    result_color: color ? { id: `rc-${color}`, key: color, name: color } : null,
    visit: {
      id: `v-${id}`,
      receipt_number: `V-${id}`,
      visit_date: date,
      practitioner: { id: 'p-1', user: { id: 'u-1', name: 'Dra. Ana López' } },
    },
    applied_age_group: { id: 'g9', code: 'G9', name: 'GRUPO 9' },
  } as unknown as IScreening
}

function renderPanel(screenings: IScreening[]) {
  const onNew = vi.fn()
  render(
    <MemoryRouter>
      <ChildScreeningsPanel screenings={screenings} isLoading={false} onNew={onNew} />
    </MemoryRouter>
  )
  return { onNew }
}

describe('ChildScreeningsPanel', () => {
  it('lists the child tests newest first with date, age, status and result', () => {
    renderPanel([
      screening('old', '2025-10-20', 'completed', 'green', 188),
      screening('new', '2026-10-21', 'draft', null, 554),
      screening('mid', '2026-04-14', 'completed', 'yellow', 364),
    ])

    const rows = screen.getAllByRole('listitem')
    expect(rows.map((row) => within(row).getByText(/V-/).textContent)).toEqual(['V-new', 'V-mid', 'V-old'])
    expect(within(rows[0]).getByText('Borrador')).toBeInTheDocument()
    expect(within(rows[0]).getByText('18 meses 14 días')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Cerrada')).toBeInTheDocument()
  })

  it('continues an open test and opens the detail of a closed one', () => {
    renderPanel([screening('new', '2026-10-21', 'draft', null, 554), screening('mid', '2026-04-14', 'completed', 'yellow', 364)])

    expect(screen.getByRole('link', { name: 'Continuar captura' })).toHaveAttribute('href', '/screening/new/apply')
    expect(screen.getByRole('link', { name: 'Ver resultado' })).toHaveAttribute('href', '/screening/mid')
  })

  it('starts a new test from the panel, also when there are none yet', () => {
    const { onNew } = renderPanel([])

    expect(screen.getByText('Este niño todavía no tiene Pruebas EDI.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Nueva Prueba EDI' }))
    expect(onNew).toHaveBeenCalled()
  })
})
