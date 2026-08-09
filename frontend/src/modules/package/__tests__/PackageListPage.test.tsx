/**
 * Smoke tests para PackageListPage.
 *
 * (1) Estado vacío — cuando la tienda no tiene entidades muestra "Sin paquetes".
 * (2) Redirect — residentes sin create.package ni read.report_package son
 *     redirigidos a /my-packages (el encabezado "Paquetería" no se renderiza).
 */
import { PackageListPage } from '@/modules/package/PackageListPage'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Core hooks ─────────────────────────────────────────────────────────────────

vi.mock('@/shared/hooks/usePermissions', () => ({
  useMultiplePermissions: vi.fn(),
  usePermissions: vi.fn(() => false),
}))

vi.mock('@/shared/hooks/useBaseList', () => ({
  useBaseList: () => ({
    onPageChanged: vi.fn(),
    onSearch: vi.fn(),
    paramsSearch: { sort: [], includes: [], filters: [] },
  }),
}))

vi.mock('@/modules/package/store/packageStore', () => ({
  usePackageStore: vi.fn((selector: (s: object) => unknown) => {
    const state = { entities: [], pagination: null, isLoading: false }
    return typeof selector === 'function' ? selector(state) : state
  }),
}))

vi.mock('@/modules/package/services/PackageResource', () => ({
  PackageResource: {
    search: vi.fn().mockResolvedValue({ data: [], meta: {} }),
    aging: vi.fn().mockResolvedValue({
      generated_at: '',
      total_pending: 0,
      buckets: {
        d0_2: { count: 0, packages: [] },
        d3_7: { count: 0, packages: [] },
        d7_plus: { count: 0, packages: [] },
      },
    }),
    agingExport: vi.fn().mockResolvedValue(new Blob()),
  },
}))

vi.mock('@/shared/hooks/useCatalogs', () => ({
  useLazyCatalogs: () => ({ catalogs: {}, enable: vi.fn(), isLoading: false }),
  CatalogType: {
    UNIT: 'unit',
    PACKAGE_CARRIER: 'package_carrier',
    PACKAGE_STATUS: 'package_status',
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ── Stubs de componentes hijos complejos ───────────────────────────────────────

vi.mock('@/modules/package/PackageForm', () => ({ PackageForm: () => null }))
vi.mock('@/modules/package/PackagePickupModal', () => ({ PackagePickupModal: () => null }))
vi.mock('@/modules/package/components/PackageRowActions', () => ({ PackageRowActions: () => null }))
vi.mock('@/modules/package/components/PackageAgingTab', () => ({ PackageAgingTab: () => null }))

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

const mockMultiple = vi.mocked(useMultiplePermissions)

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('PackageListPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('muestra "Sin paquetes" cuando la tienda no tiene entidades', () => {
    mockMultiple.mockReturnValue({ canCreate: true, canReport: false })

    render(<PackageListPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Sin paquetes')).toBeInTheDocument()
  })

  it('no renderiza el encabezado "Paquetería" cuando el usuario no tiene permisos de gestión (redirect a /my-packages)', () => {
    // Residents/renters have neither create.package nor read.report_package →
    // the component returns <Navigate to="/my-packages"> before rendering content.
    mockMultiple.mockReturnValue({ canCreate: false, canReport: false })

    render(<PackageListPage />, { wrapper: makeWrapper() })

    expect(screen.queryByText('Paquetería')).not.toBeInTheDocument()
  })
})
