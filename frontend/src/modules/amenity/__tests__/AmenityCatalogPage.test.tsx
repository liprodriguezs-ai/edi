/**
 * Smoke tests para AmenityCatalogPage (S14 — catálogo del residente).
 *
 * (1) Estado vacío — "Sin amenidades disponibles".
 * (2) Con entidades — renderiza el nombre + badge "No disponible" cuando
 *     out_of_service.
 */
import { AmenityCatalogPage } from '@/modules/amenity/AmenityCatalogPage'
import type { IAmenity } from '@/modules/amenity/schema'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/hooks/useBaseList', () => ({
  useBaseList: () => ({
    onPageChanged: vi.fn(),
    onSearch: vi.fn(),
    onReset: vi.fn(),
    paramsSearch: { sort: [], includes: [], filters: [] },
  }),
}))

let mockEntities: IAmenity[] = []

vi.mock('@/modules/amenity/store/amenityStore', () => ({
  useAmenityStore: vi.fn((selector: (s: object) => unknown) => {
    const state = {
      entities: mockEntities,
      pagination: { page: 1, pages: 1, total: mockEntities.length, per_page: 20 },
      isLoading: false,
    }
    return typeof selector === 'function' ? selector(state) : state
  }),
}))

vi.mock('@/modules/amenity/services/AmenityResource', () => ({
  AmenityResource: {
    search: vi.fn().mockResolvedValue({ data: [], meta: {} }),
  },
}))

vi.mock('@/shared/components/SearchList', () => ({
  SearchList: ({ children }: { children: ReactNode }) => <>{children}</>,
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

describe('AmenityCatalogPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockEntities = []
  })

  it('muestra "Sin amenidades disponibles" cuando no hay amenidades', () => {
    render(<AmenityCatalogPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Sin amenidades disponibles')).toBeInTheDocument()
  })

  it('renderiza la tarjeta de una amenidad disponible', () => {
    mockEntities = [
      {
        id: 'amenity-1',
        name: 'Alberca',
        capacity: 20,
        available_weekdays: [0, 6],
        reservation_mode_id: 'mode-1',
        has_cost: false,
        out_of_service: false,
      } as IAmenity,
    ]

    render(<AmenityCatalogPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Alberca')).toBeInTheDocument()
    expect(screen.getByText('Sin costo')).toBeInTheDocument()
    expect(screen.queryByText('No disponible')).not.toBeInTheDocument()
  })

  it('marca "No disponible" cuando la amenidad está fuera de servicio', () => {
    mockEntities = [
      {
        id: 'amenity-2',
        name: 'Salón de eventos',
        capacity: 40,
        available_weekdays: [5, 6],
        reservation_mode_id: 'mode-1',
        has_cost: true,
        cost_amount: 50000,
        out_of_service: true,
      } as IAmenity,
    ]

    render(<AmenityCatalogPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Salón de eventos')).toBeInTheDocument()
    expect(screen.getByText('No disponible')).toBeInTheDocument()
  })
})
