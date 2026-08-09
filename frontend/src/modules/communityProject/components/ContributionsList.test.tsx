/**
 * Tests de la lista de aportaciones compartida (§11): matriz de acciones por rol Y por
 * fila (mesa vs residente en filas propias/ajenas), pill "Pago en revisión" y visibilidad
 * de filtros según el flag de privacidad.
 *
 * Estrategia: se mockean los resources y hooks de datos; se envuelve con QueryClientProvider
 * (useQuery) + MemoryRouter (los <Link> de la tabla). Los modales se stubean a null (solo
 * interesan las acciones que la lista pinta, no su interior).
 */
import { ContributionsList } from '@/modules/communityProject/components/ContributionsList'
import {
  COMMUNITY_PROJECT_STATUS,
  PARTICIPATION_TYPE,
  type ICommunityProject,
  type IProjectContribution,
} from '@/modules/communityProject/schema'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { ProjectContributionResource } from '@/modules/communityProject/services/ProjectContributionResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(() => false),
}))

vi.mock('@/modules/communityProject/services/ProjectContributionResource', () => ({
  ProjectContributionResource: { search: vi.fn(), decline: vi.fn(), revertDecline: vi.fn() },
}))

vi.mock('@/modules/communityProject/services/CommunityProjectResource', () => ({
  CommunityProjectResource: { contributionPayments: vi.fn() },
}))

vi.mock('@/modules/unit/services/UnitResource', () => ({
  UnitResource: { my: vi.fn() },
}))

// Stub de los modales: solo importa que la lista los ofrezca, no su interior.
vi.mock('@/modules/communityProject/components/ContributeModal', () => ({
  ContributeModal: () => null,
}))
vi.mock('@/modules/communityProject/components/ReportPaymentModal', () => ({
  ReportPaymentModal: () => null,
}))

// Mantiene CatalogType real y evita el POST /selects del filtro de estado.
vi.mock('@/shared/hooks/useCatalogs', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/shared/hooks/useCatalogs')>()
  return {
    ...actual,
    useLazyCatalogs: () => ({ catalogs: {}, enable: vi.fn(), isLoading: false, isEnabled: false }),
    useCatalogs: () => ({ catalogs: {}, isLoading: false }),
  }
})

const mockSearch = vi.mocked(ProjectContributionResource.search)
const mockPending = vi.mocked(CommunityProjectResource.contributionPayments)
const mockMyUnits = vi.mocked(UnitResource.my)
const mockUsePermissions = vi.mocked(usePermissions)

// ── Fixtures ─────────────────────────────────────────────────────────────────
const PROJECT: ICommunityProject = {
  id: 'p1',
  title: 'Proyecto de jardines',
  participation_type_id: PARTICIPATION_TYPE.VOLUNTARY,
  community_project_status_id: COMMUNITY_PROJECT_STATUS.ACTIVE,
  payments_visible_to_residents: true,
}

function makeContribution(
  id: string,
  unitId: string,
  overrides: Partial<IProjectContribution> = {}
): IProjectContribution {
  return {
    id,
    community_project_id: 'p1',
    unit_id: unitId,
    expected_amount: 10000,
    contributed_amount: 0,
    declined: false,
    project_contribution_status_id: 'st-pending',
    unit: { id: unitId, number: unitId, full_identifier: `Casa ${unitId}` },
    contribution_status: { id: 'st-pending', name: 'Pendiente' },
    ...overrides,
  }
}

function mockSearchRows(rows: IProjectContribution[]) {
  mockSearch.mockResolvedValue({
    data: rows,
    meta: {
      current_page: 1,
      per_page: 20,
      total: rows.length,
      last_page: 1,
      from: 1,
      to: rows.length,
    },
  })
}

function renderList(props: Parameters<typeof ContributionsList>[0]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  )
  return render(<ContributionsList {...props} />, { wrapper })
}

beforeEach(() => {
  vi.clearAllMocks()
  mockUsePermissions.mockReturnValue(false)
  mockMyUnits.mockResolvedValue([])
  mockPending.mockResolvedValue([])
  mockSearchRows([])
})

// ── Tests ────────────────────────────────────────────────────────────────────
describe('ContributionsList — action matrix by role and row', () => {
  it('board sees Aportar + Declinar on every contributable row, plus filters', async () => {
    mockUsePermissions.mockReturnValue(true)
    mockSearchRows([makeContribution('c1', 'u1'), makeContribution('c2', 'u2')])

    renderList({ projectId: 'p1', project: PROJECT })

    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
    const table = within(screen.getByRole('table'))
    expect(table.getAllByText('Aportar')).toHaveLength(2)
    expect(table.getAllByText('Declinar')).toHaveLength(2)
    // Filtros/búsqueda visibles para la mesa.
    expect(screen.getByPlaceholderText('Buscar por unidad o proyecto…')).toBeInTheDocument()
    // El residente no puede reportar cuando es mesa.
    expect(table.queryByText('Reportar pago')).not.toBeInTheDocument()
  })

  it('resident sees Reportar pago only on their own unit rows, nothing on others', async () => {
    mockUsePermissions.mockReturnValue(false)
    mockMyUnits.mockResolvedValue([{ id: 'u1' }] as never)
    mockSearchRows([makeContribution('c1', 'u1'), makeContribution('c2', 'u2')])

    renderList({ projectId: 'p1', project: PROJECT })

    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
    const table = within(screen.getByRole('table'))
    await waitFor(() => expect(table.getAllByText('Reportar pago')).toHaveLength(1))
    expect(table.queryByText('Aportar')).not.toBeInTheDocument()
    expect(table.queryByText('Declinar')).not.toBeInTheDocument()
  })

  it('shows the "Pago en revisión" pill instead of Reportar when a report is pending', async () => {
    mockUsePermissions.mockReturnValue(false)
    mockMyUnits.mockResolvedValue([{ id: 'u1' }] as never)
    mockSearchRows([makeContribution('c1', 'u1')])
    mockPending.mockResolvedValue([{ id: 'pay1', earmark_id: 'c1' }] as never)

    renderList({ projectId: 'p1', project: PROJECT })

    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
    const table = within(screen.getByRole('table'))
    await waitFor(() => expect(table.getByText('Pago en revisión')).toBeInTheDocument())
    expect(table.queryByText('Reportar pago')).not.toBeInTheDocument()
  })
})

describe('ContributionsList — filter visibility by privacy flag', () => {
  it('hides filters for a resident when payments are not visible', async () => {
    mockUsePermissions.mockReturnValue(false)
    mockMyUnits.mockResolvedValue([{ id: 'u1' }] as never)
    mockSearchRows([makeContribution('c1', 'u1')])

    renderList({ projectId: 'p1', project: { ...PROJECT, payments_visible_to_residents: false } })

    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
    const table = within(screen.getByRole('table'))
    await waitFor(() => expect(table.getByText('Casa u1')).toBeInTheDocument())
    expect(screen.queryByPlaceholderText('Buscar por unidad o proyecto…')).not.toBeInTheDocument()
    // Su fila sigue con la acción de reportar.
    expect(table.getByText('Reportar pago')).toBeInTheDocument()
  })

  it('shows filters for a resident when payments are visible', async () => {
    mockUsePermissions.mockReturnValue(false)
    mockMyUnits.mockResolvedValue([{ id: 'u1' }] as never)
    mockSearchRows([makeContribution('c1', 'u1')])

    renderList({ projectId: 'p1', project: { ...PROJECT, payments_visible_to_residents: true } })

    await waitFor(() =>
      expect(screen.getByPlaceholderText('Buscar por unidad o proyecto…')).toBeInTheDocument()
    )
  })
})
