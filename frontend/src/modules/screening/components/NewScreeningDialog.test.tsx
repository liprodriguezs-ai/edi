import { NewScreeningDialog } from '@/modules/screening/components/NewScreeningDialog'
import type { IScreening } from '@/modules/screening/schema'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = {
  profile: { user: { id: 'u-1', email: 'qa@salud.mx' }, abilities: ['*'], roles: [], tenant: { timezone: 'UTC' } },
}

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

vi.mock('@/shared/hooks/useCatalogs', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/shared/hooks/useCatalogs')>()
  return {
    ...original,
    useCatalogs: () => ({
      catalogs: {
        [original.CatalogType.HEALTH_CENTER]: [{ id: 'hc-1', name: 'CS Tlalpan' }],
        [original.CatalogType.PRACTITIONER_PROFILE]: [
          { id: 'pp-2', name: 'Otro', user_id: 'u-2', user: { id: 'u-2', name: 'Dr. Ruiz' } },
          { id: 'pp-1', name: 'Yo', user_id: 'u-1', user: { id: 'u-1', name: 'Dra. López' } },
        ],
      },
      isLoading: false,
    }),
  }
})

vi.mock('@/modules/visit/services/VisitResource', () => ({
  VisitResource: { search: vi.fn(), create: vi.fn() },
}))

vi.mock('@/modules/screening/services/ScreeningResource', () => ({
  ScreeningResource: { findByVisitId: vi.fn() },
}))

import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import { tenantToday } from '@/shared/utils/tenantTime'

const TODAY = tenantToday('UTC')

const CHILD = { id: 'c-1', health_center_id: 'hc-1' }

function LocationProbe() {
  const location = useLocation()
  return <p data-testid='location'>{location.pathname + location.search}</p>
}

function renderDialog(openScreening: IScreening | null = null) {
  render(
    <MemoryRouter initialEntries={['/child/c-1']}>
      <Routes>
        <Route
          path='/child/:id'
          element={<NewScreeningDialog isOpen child={CHILD} openScreening={openScreening} onClose={vi.fn()} />}
        />
        <Route path='*' element={<LocationProbe />} />
      </Routes>
    </MemoryRouter>
  )
}

beforeEach(() => {
  vi.mocked(VisitResource.search).mockReset()
  vi.mocked(VisitResource.create).mockReset()
  vi.mocked(ScreeningResource.findByVisitId).mockReset()
})

describe('NewScreeningDialog', () => {
  it("reuses today's visit when it has no test yet", async () => {
    vi.mocked(VisitResource.search).mockResolvedValue({ data: [{ id: 'v-today', receipt_number: 'V-2026-000418', visit_date: TODAY }, { id: 'v-old', receipt_number: 'V-2026-000100', visit_date: '2026-04-14' }] } as never)
    vi.mocked(ScreeningResource.findByVisitId).mockResolvedValue(null)
    renderDialog()

    expect(await screen.findByText(/V-2026-000418/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Empezar Prueba EDI' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/screening/apply/v-today'))
    expect(VisitResource.create).not.toHaveBeenCalled()
  })

  it("creates today's visit with the child's center and the user's own practitioner profile", async () => {
    vi.mocked(VisitResource.search).mockResolvedValue({ data: [{ id: 'v-old', visit_date: '2026-04-14' }] } as never)
    vi.mocked(ScreeningResource.findByVisitId).mockResolvedValue(null)
    vi.mocked(VisitResource.create).mockResolvedValue({ id: 'v-new' } as never)
    renderDialog()

    fireEvent.click(await screen.findByRole('button', { name: 'Empezar Prueba EDI' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/screening/apply/v-new'))
    expect(VisitResource.create).toHaveBeenCalledWith(
      expect.objectContaining({
        child_id: 'c-1',
        health_center_id: 'hc-1',
        practitioner_id: 'pp-1',
        visit_date: TODAY,
      })
    )
  })

  it('continues the test already started today instead of opening a second one', async () => {
    vi.mocked(VisitResource.search).mockResolvedValue({ data: [{ id: 'v-today', receipt_number: 'V-1', visit_date: TODAY }] } as never)
    vi.mocked(ScreeningResource.findByVisitId).mockResolvedValue({
      id: 's-9',
      screening_status: { id: 'st', key: 'draft', name: 'Borrador' },
    } as IScreening)
    renderDialog()

    fireEvent.click(await screen.findByRole('button', { name: 'Continuar captura' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/screening/s-9/apply'))
    expect(screen.queryByRole('button', { name: 'Empezar Prueba EDI' })).not.toBeInTheDocument()
  })

  it('warns about an unfinished test from another day and still allows a new one', async () => {
    vi.mocked(VisitResource.search).mockResolvedValue({ data: [] } as never)
    renderDialog({
      id: 's-old',
      screening_status: { id: 'st', key: 'draft', name: 'Borrador' },
      visit: { id: 'v-old', visit_date: '2026-10-20' },
    } as IScreening)

    expect(await screen.findByText(/tiene una Prueba EDI sin terminar del/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Empezar Prueba EDI' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Continuar esa prueba' }))
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/screening/s-old/apply'))
  })
})
