/**
 * Smoke tests para AmenityListPage.
 *
 * (1) Estado vacío — cuando la tienda no tiene entidades muestra "Sin amenidades registradas".
 * (2) Con entidades — renderiza el nombre de la amenidad.
 * (3) Redirect (S17) — un viewer sin update.amenity ni delete.amenity (residentes,
 *     oversight committee) es redirigido a /amenities (el encabezado "Amenidades"
 *     de este CRUD admin no se renderiza).
 */
import { AmenityListPage } from '@/modules/amenity/AmenityListPage'
import type { IAmenity } from '@/modules/amenity/schema'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(() => false),
}))

vi.mock('@/shared/hooks/useBaseList', () => ({
  useBaseList: () => ({
    onPageChanged: vi.fn(),
    onSearch: vi.fn(),
    onReset: vi.fn(),
    onRemove: vi.fn(),
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
      isDeleting: false,
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

const mockPermissions = vi.mocked(usePermissions)

describe('AmenityListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockEntities = []
    // Admin viewer by default (canEdit=true unblocks the S17 redirect guard);
    // the redirect test below overrides this to false.
    mockPermissions.mockReturnValue(true)
  })

  it('muestra "Sin amenidades registradas" cuando la tienda no tiene entidades', () => {
    render(<AmenityListPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Sin amenidades registradas')).toBeInTheDocument()
  })

  it('renderiza el nombre de la amenidad cuando hay entidades', () => {
    mockEntities = [
      {
        id: 'amenity-1',
        name: 'Salón de eventos',
        capacity: 40,
        available_weekdays: [5, 6],
        reservation_mode_id: 'mode-1',
        has_cost: false,
        out_of_service: false,
      } as IAmenity,
    ]

    render(<AmenityListPage />, { wrapper: makeWrapper() })

    expect(screen.getAllByText('Salón de eventos').length).toBeGreaterThan(0)
  })

  it('redirige a /amenities cuando el viewer no tiene update.amenity ni delete.amenity', () => {
    mockPermissions.mockReturnValue(false)

    render(<AmenityListPage />, { wrapper: makeWrapper() })

    expect(screen.queryByText('Amenidades')).not.toBeInTheDocument()
  })
})
