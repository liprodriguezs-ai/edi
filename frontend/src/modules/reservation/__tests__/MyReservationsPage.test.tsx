/**
 * Smoke tests para MyReservationsPage (S16 — "Mis reservaciones" del residente).
 *
 * (1) Sin unidades propias → "Sin reservaciones" (no queda cargando para siempre).
 * (2) Con una reservación → muestra amenidad/estado/saldo y el botón "Pagar"
 *     cuando hay saldo pendiente.
 */
import { MyReservationsPage } from '@/modules/reservation/MyReservationsPage'
import type { IAmenityReservation } from '@/modules/reservation/schema'
import { RESERVATION_STATUS } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/unit/services/UnitResource', () => ({
  UnitResource: { my: vi.fn() },
}))

vi.mock('@/modules/reservation/services/AmenityReservationResource', () => ({
  AmenityReservationResource: { search: vi.fn() },
}))

vi.mock('@/modules/amenity/services/AmenityResource', () => ({
  AmenityResource: { show: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

function makeReservation(overrides: Partial<IAmenityReservation> = {}): IAmenityReservation {
  return {
    id: 'res-1',
    amenity_id: 'amenity-1',
    unit_id: 'unit-1',
    reservation_date: '2026-08-01',
    reservation_status_id: RESERVATION_STATUS.PENDING_PAYMENT,
    amount_outstanding: 50000,
    amenity: { id: 'amenity-1', name: 'Salón de eventos' },
    reservation_status: { id: RESERVATION_STATUS.PENDING_PAYMENT, name: 'Pendiente de pago' },
    ...overrides,
  } as IAmenityReservation
}

const mockMy = vi.mocked(UnitResource.my)
const mockSearch = vi.mocked(AmenityReservationResource.search)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

describe('MyReservationsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('muestra "Sin reservaciones" cuando el residente no tiene unidades propias', async () => {
    mockMy.mockResolvedValue([])

    render(<MyReservationsPage />, { wrapper: makeWrapper() })

    expect(await screen.findByText('Sin reservaciones')).toBeInTheDocument()
    expect(mockSearch).not.toHaveBeenCalled()
  })

  it('lista las reservaciones de las unidades propias y ofrece "Pagar" cuando hay saldo', async () => {
    mockMy.mockResolvedValue([{ id: 'unit-1', full_identifier: 'Casa 1' } as never])
    mockSearch.mockResolvedValue({
      data: [makeReservation()],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 20 } as never,
    })

    render(<MyReservationsPage />, { wrapper: makeWrapper() })

    expect(await screen.findAllByText('Salón de eventos')).not.toHaveLength(0)
    expect(mockSearch).toHaveBeenCalledWith(
      expect.objectContaining({
        filters: [{ field: 'unit_id', operator: 'in', value: ['unit-1'] }],
      })
    )
    expect(screen.getAllByRole('button', { name: 'Pagar' }).length).toBeGreaterThan(0)
  })

  it('muestra "Pago en revisión" y oculta "Pagar" cuando hay un pago earmark pendiente de validación', async () => {
    mockMy.mockResolvedValue([{ id: 'unit-1', full_identifier: 'Casa 1' } as never])
    mockSearch.mockResolvedValue({
      data: [makeReservation({ has_pending_payment: true })],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 20 } as never,
    })

    render(<MyReservationsPage />, { wrapper: makeWrapper() })

    expect(await screen.findAllByText('Pago en revisión')).not.toHaveLength(0)
    expect(screen.queryByRole('button', { name: 'Pagar' })).not.toBeInTheDocument()
  })

  it('no afecta el flujo normal cuando has_pending_payment es false', async () => {
    mockMy.mockResolvedValue([{ id: 'unit-1', full_identifier: 'Casa 1' } as never])
    mockSearch.mockResolvedValue({
      data: [makeReservation({ has_pending_payment: false })],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 20 } as never,
    })

    render(<MyReservationsPage />, { wrapper: makeWrapper() })

    expect(await screen.findAllByRole('button', { name: 'Pagar' })).not.toHaveLength(0)
    expect(screen.queryByText('Pago en revisión')).not.toBeInTheDocument()
  })

  it('muestra la columna "Vence" con payment_deadline para una reservación PENDING_PAYMENT', async () => {
    mockMy.mockResolvedValue([{ id: 'unit-1', full_identifier: 'Casa 1' } as never])
    mockSearch.mockResolvedValue({
      data: [
        makeReservation({
          reservation_status_id: RESERVATION_STATUS.PENDING_PAYMENT,
          payment_deadline: '2026-08-10',
          balance_due_date: null,
        }),
      ],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 20 } as never,
    })

    render(<MyReservationsPage />, { wrapper: makeWrapper() })

    expect(
      await screen.findAllByText(displayFormatDate('2026-08-10', 'mediumDate'))
    ).not.toHaveLength(0)
  })

  it('muestra la columna "Vence" con balance_due_date para una reservación CONFIRMED', async () => {
    mockMy.mockResolvedValue([{ id: 'unit-1', full_identifier: 'Casa 1' } as never])
    mockSearch.mockResolvedValue({
      data: [
        makeReservation({
          reservation_status_id: RESERVATION_STATUS.CONFIRMED,
          payment_deadline: null,
          balance_due_date: '2026-09-05',
          amount_outstanding: 10000,
        }),
      ],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 20 } as never,
    })

    render(<MyReservationsPage />, { wrapper: makeWrapper() })

    expect(
      await screen.findAllByText(displayFormatDate('2026-09-05', 'mediumDate'))
    ).not.toHaveLength(0)
  })
})
