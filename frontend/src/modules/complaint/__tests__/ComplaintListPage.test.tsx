/**
 * Smoke tests para ComplaintListPage.
 *
 * (1) Estado vacío — cuando la tienda no tiene entidades muestra "Sin incidencias".
 * (2) Con entidades — la tabla renderiza el número de caso de la incidencia.
 */
import { ComplaintListPage } from '@/modules/complaint/ComplaintListPage'
import { useComplaintStore } from '@/modules/complaint/store/complaintStore'
import type { IComplaint } from '@/modules/complaint/schema'
import type { IEntityStore } from '@/shared/store/EntityStore'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Selector type shared by the module-level mock factory and mockImplementation()
// calls below — typing it as `(s: object) => unknown` triggers TS2345 against
// the real (overloaded) zustand hook signature when tsc -b checks this file.
type ComplaintStoreSelector = (_state: IEntityStore<IComplaint>) => unknown

// ── Core hooks ─────────────────────────────────────────────────────────────────

vi.mock('@/shared/hooks/useBaseList', () => ({
  useBaseList: () => ({
    onPageChanged: vi.fn(),
    onSearch: vi.fn(),
    paramsSearch: { sort: [], includes: [], filters: [] },
  }),
}))

vi.mock('@/modules/complaint/store/complaintStore', () => ({
  useComplaintStore: vi.fn((selector: ComplaintStoreSelector) => {
    const state = { entities: [], pagination: null, isLoading: false }
    return typeof selector === 'function'
      ? selector(state as unknown as IEntityStore<IComplaint>)
      : state
  }),
}))

vi.mock('@/modules/complaint/services/ComplaintResource', () => ({
  ComplaintResource: {
    search: vi.fn().mockResolvedValue({ data: [], meta: {} }),
    // Registration-eligibility gate (UX fix): the button is only asserted
    // indirectly here — PermissionGuard also requires create.complaint from
    // sessionStore, which is unmocked and empty in this smoke test, so the
    // button stays hidden regardless of can_register. This mock just keeps
    // ComplaintListPage's useQuery from throwing on an unmocked method.
    registrationEligibility: vi.fn().mockResolvedValue({ can_register: true }),
  },
}))

vi.mock('@/shared/hooks/useCatalogs', () => ({
  useLazyCatalogs: () => ({ catalogs: {}, enable: vi.fn(), isLoading: false }),
  CatalogType: {
    COMPLAINT_STATUS: 'complaint_status',
    COMPLAINT_CATEGORY: 'complaint_category',
    COMPLAINT_PRIORITY: 'complaint_priority',
    USER: 'user',
    UNIT: 'unit',
  },
}))

// ── Stubs de componentes hijos complejos ───────────────────────────────────────

vi.mock('@/modules/complaint/ComplaintForm', () => ({ ComplaintForm: () => null }))

// SearchList renderiza sus children; el stub lo replica de forma mínima.
vi.mock('@/shared/components/SearchList', () => ({
  SearchList: ({ children }: { children: ReactNode }) => <>{children}</>,
}))

// MultiSelectFilter: solo UI, no necesario para los smoke tests.
vi.mock('@/shared/components/filters/MultiSelectFilter', () => ({
  MultiSelectFilter: () => null,
}))

// ── Helpers ────────────────────────────────────────────────────────────────────

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

const mockUseComplaintStore = vi.mocked(useComplaintStore)

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('ComplaintListPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('muestra "Sin incidencias" cuando la tienda no tiene entidades', () => {
    mockUseComplaintStore.mockImplementation((selector: ComplaintStoreSelector) => {
      const state = { entities: [], pagination: null, isLoading: false }
      return typeof selector === 'function'
        ? selector(state as unknown as IEntityStore<IComplaint>)
        : state
    })

    render(<ComplaintListPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Sin incidencias')).toBeInTheDocument()
  })

  it('renderiza el número de caso de la incidencia cuando hay entidades', () => {
    mockUseComplaintStore.mockImplementation((selector: ComplaintStoreSelector) => {
      const state = {
        entities: [
          {
            id: 'complaint-1',
            case_number: 'D-2026-000001',
            title: 'Ruido excesivo',
            complaint_category_id: 'cat-1',
            complaint_priority_id: 'pri-1',
            complaint_status_id: 'status-1',
            reporter_user_id: 'user-1',
            against_description: 'Vecino con música alta',
            created_at: '2026-07-19T00:00:00Z',
          },
        ],
        pagination: { page: 1, pages: 1, total: 1, per_page: 20 },
        isLoading: false,
      }
      return typeof selector === 'function'
        ? selector(state as unknown as IEntityStore<IComplaint>)
        : state
    })

    render(<ComplaintListPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('D-2026-000001')).toBeInTheDocument()
    expect(screen.queryByText('Sin incidencias')).not.toBeInTheDocument()
  })
})
