/**
 * Tests for CommunityProjectDetailPage.
 *
 * Focus: the 404 / error / success gating fixed recently. On a 404 the page
 * renders an EntityNotFound state instead of hanging on "Cargando proyecto…",
 * and the dependent `summary` query only fires after the main project query
 * actually succeeds (enabled: isSuccess).
 *
 * Strategy: mock CommunityProjectResource + toast + usePermissions and stub the
 * heavy children (contributions / documents / edit modal) that bring their own
 * data, so only the page's own gating is under test. Wrap with
 * QueryClientProvider + MemoryRouter using the real route path.
 */
import { CommunityProjectDetailPage } from '@/modules/communityProject/CommunityProjectDetailPage'
import {
  COMMUNITY_PROJECT_STATUS,
  PARTICIPATION_TYPE,
  type ICommunityProject,
  type ProjectSummary,
} from '@/modules/communityProject/schema'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { ApiError } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(() => false),
}))

vi.mock('@/modules/communityProject/services/CommunityProjectResource', () => ({
  CommunityProjectResource: {
    show: vi.fn(),
    summary: vi.fn(),
    activate: vi.fn(),
    close: vi.fn(),
    cancelProject: vi.fn(),
    sendReminder: vi.fn(),
  },
}))

// Los hijos pesados traen su propia data por dentro; se stubean para dejar solo
// la lógica de gating de la página bajo prueba (y para que summary lo dispare
// únicamente la página, no un hijo).
vi.mock('@/modules/communityProject/components/ContributionsList', () => ({
  ContributionsList: () => <div data-testid='contributions-list' />,
}))
vi.mock('@/modules/communityProject/components/ProjectDocuments', () => ({
  ProjectDocuments: () => <div data-testid='project-documents' />,
}))
vi.mock('@/modules/communityProject/components/ProjectEditModal', () => ({
  ProjectEditModal: () => null,
}))

const mockShow = vi.mocked(CommunityProjectResource.show)
const mockSummary = vi.mocked(CommunityProjectResource.summary)

// ── Fixtures ─────────────────────────────────────────────────────────────────
function makeProject(overrides: Partial<ICommunityProject> = {}): ICommunityProject {
  return {
    id: 'proj-1',
    title: 'Bardas perimetrales',
    participation_type_id: PARTICIPATION_TYPE.MANDATORY,
    community_project_status_id: COMMUNITY_PROJECT_STATUS.ACTIVE,
    goal_amount: 500000,
    due_date: null,
    description: undefined,
    description_html: null,
    status: { id: COMMUNITY_PROJECT_STATUS.ACTIVE, name: 'Activo' },
    category: { id: 'cat-1', name: 'Infraestructura' },
    participation_type: { id: PARTICIPATION_TYPE.MANDATORY, name: 'Obligatorio' },
    media: [],
    ...overrides,
  }
}

function makeSummary(): ProjectSummary {
  return {
    raised: { amount: '150000', currency: 'MXN', formatted: '$1,500.00' },
    executed: { amount: '0', currency: 'MXN', formatted: '$0.00' },
    available: { amount: '150000', currency: 'MXN', formatted: '$1,500.00' },
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/community-project/proj-1']}>
        <Routes>
          <Route path='/community-project/:id' element={children} />
          <Route path='/community-project' element={<div>Lista de proyectos</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
  return render(<CommunityProjectDetailPage />, { wrapper: Wrapper })
}

describe('CommunityProjectDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders a not-found state on a 404 and never fires the dependent summary query', async () => {
    mockShow.mockRejectedValue(new ApiError('No encontrado', 404))
    renderDetail()

    expect(await screen.findByText('Proyecto no encontrado')).toBeInTheDocument()
    // La query dependiente (summary) está gateada en `isSuccess` → jamás corre en 404.
    expect(mockSummary).not.toHaveBeenCalled()
    // Link de regreso a la lista.
    expect(screen.getByRole('link', { name: 'Volver a proyectos' })).toBeInTheDocument()
  })

  it('renders a generic error state with retry on a non-404 failure', async () => {
    mockShow.mockRejectedValue(new ApiError('Server error', 500))
    renderDetail()

    expect(await screen.findByText('No se pudo cargar la información')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reintentar' })).toBeInTheDocument()
    expect(mockSummary).not.toHaveBeenCalled()
  })

  it('renders the project and fires the summary query once the main query succeeds', async () => {
    mockShow.mockResolvedValue(makeProject())
    mockSummary.mockResolvedValue(makeSummary())
    renderDetail()

    expect(await screen.findByText('Bardas perimetrales')).toBeInTheDocument()
    // El gating se abre tras el success → summary corre con el id de la URL.
    await waitFor(() => expect(mockSummary).toHaveBeenCalledWith('proj-1'))
  })
})
