/**
 * Smoke tests para AmenityReservationListPage.
 *
 * (1) Estado vacío — cuando la tienda no tiene entidades muestra el mensaje vacío.
 * (2) Con entidades — renderiza el nombre de la amenidad y la unidad.
 * (3) Redirect (S17) — un viewer sin update.amenity_reservation (residentes,
 *     oversight committee) es redirigido a /my-reservations (el encabezado
 *     "Reservaciones" de este inbox admin no se renderiza).
 */
import { AmenityReservationListPage } from '@/modules/reservation/AmenityReservationListPage'
import { RESERVATION_STATUS, type IAmenityReservation } from '@/modules/reservation/schema'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(() => true),
}))

vi.mock('@/shared/hooks/useBaseList', () => ({
  useBaseList: () => ({
    onPageChanged: vi.fn(),
    onSearch: vi.fn(),
    onReset: vi.fn(),
    paramsSearch: { sort: [], includes: [], filters: [] },
  }),
}))

let mockEntities: IAmenityReservation[] = []

vi.mock('@/modules/reservation/store/amenityReservationStore', () => ({
  useAmenityReservationStore: vi.fn((selector: (s: object) => unknown) => {
    const state = {
      entities: mockEntities,
      pagination: { page: 1, pages: 1, total: mockEntities.length, per_page: 20 },
      isLoading: false,
    }
    return typeof selector === 'function' ? selector(state) : state
  }),
}))

vi.mock('@/modules/reservation/services/AmenityReservationResource', () => ({
  AmenityReservationResource: {
    search: vi.fn().mockResolvedValue({ data: [], meta: {} }),
  },
}))

vi.mock('@/shared/components/SearchList', () => ({
  SearchList: ({ children }: { children: ReactNode }) => <>{children}</>,
}))

vi.mock('@/modules/reservation/components/ReservationFiltersPanel', () => ({
  ReservationFiltersPanel: () => null,
}))

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

const mockPermissions = vi.mocked(usePermissions)

describe('AmenityReservationListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockEntities = []
    // Board/admin viewer by default (canManage=true unblocks the S17 redirect
    // guard); the redirect test below overrides this to false.
    mockPermissions.mockReturnValue(true)
  })

  it('muestra el estado vacío cuando la tienda no tiene entidades', () => {
    render(<AmenityReservationListPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Sin reservaciones registradas')).toBeInTheDocument()
  })

  it('renderiza la amenidad y la unidad cuando hay entidades', () => {
    mockEntities = [
      {
        id: 'reservation-1',
        amenity_id: 'amenity-1',
        unit_id: 'unit-1',
        reservation_date: '2026-08-01',
        reservation_status_id: RESERVATION_STATUS.CONFIRMED,
        amount_outstanding: 0,
        amenity: { id: 'amenity-1', name: 'Salón de eventos' },
        unit: { id: 'unit-1', full_identifier: 'Casa 12' },
        reservation_status: { id: RESERVATION_STATUS.CONFIRMED, name: 'Confirmada' },
      } as IAmenityReservation,
    ]

    render(<AmenityReservationListPage />, { wrapper: makeWrapper() })

    expect(screen.getAllByText('Salón de eventos').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Casa 12').length).toBeGreaterThan(0)
  })

  it('redirige a /my-reservations cuando el viewer no tiene update.amenity_reservation', () => {
    mockPermissions.mockReturnValue(false)

    render(<AmenityReservationListPage />, { wrapper: makeWrapper() })

    expect(screen.queryByText('Reservaciones')).not.toBeInTheDocument()
  })
})
