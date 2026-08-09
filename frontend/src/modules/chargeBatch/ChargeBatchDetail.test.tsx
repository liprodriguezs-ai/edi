/**
 * Tests for ChargeBatchDetail.
 *
 * Focus: the 404 / error / success gating fixed recently. On a 404 the component
 * renders an EntityNotFound state instead of hanging on "Cargando cobro…", and
 * the dependent queries (collection settings + batch summary/stats) only fire
 * after the main batch query succeeds.
 *
 * Strategy: DO NOT mock useChargeBatchDetail / useChargeBatchStats — the gating
 * itself is under test. Instead mock the resources those hooks call
 * (ChargeBatchResource + ConfigurationResource) and stub the heavy children
 * (BatchUnitChargesTable / RegisterPaymentSheet) so the render stays focused.
 */
import { ChargeBatchDetail } from '@/modules/chargeBatch/ChargeBatchDetail'
import { CHARGE_BATCH_STATUS, type IChargeBatch } from '@/modules/chargeBatch/schema'
import {
  ChargeBatchResource,
  type ChargeBatchSummary,
} from '@/modules/chargeBatch/services/ChargeBatchResource'
import { ConfigurationResource } from '@/modules/configuration/services/ConfigurationResource'
import { ApiError } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

vi.mock('@/modules/chargeBatch/services/ChargeBatchResource', () => ({
  ChargeBatchResource: {
    show: vi.fn(),
    summary: vi.fn(),
    activate: vi.fn(),
  },
}))

vi.mock('@/modules/configuration/services/ConfigurationResource', () => ({
  ConfigurationResource: {
    getCollectionSettings: vi.fn(),
  },
}))

// Hijos pesados con su propio fetch/estado — stub para enfocar la prueba en el
// gating del contenedor, no en el detalle de la tabla o el sheet de cobro.
vi.mock('@/modules/chargeBatch/components/BatchUnitChargesTable', () => ({
  BatchUnitChargesTable: () => <div data-testid='batch-unit-charges-table' />,
}))
vi.mock('@/modules/payment/components/RegisterPaymentSheet', () => ({
  RegisterPaymentSheet: () => null,
}))

const mockShow = vi.mocked(ChargeBatchResource.show)
const mockSummary = vi.mocked(ChargeBatchResource.summary)
const mockGetSettings = vi.mocked(ConfigurationResource.getCollectionSettings)

// ── Fixtures ─────────────────────────────────────────────────────────────────
function makeBatch(overrides: Partial<IChargeBatch> = {}): IChargeBatch {
  return {
    id: 'batch-1',
    charge_policy_id: 'pol-1',
    title: 'Mantenimiento marzo 2026',
    year: 2026,
    month: 3,
    target_type: 'all',
    issued_at: '2026-03-01',
    due_date: '2026-03-31',
    charge_batch_status_id: CHARGE_BATCH_STATUS.ACTIVE,
    policy: null,
    status: { id: CHARGE_BATCH_STATUS.ACTIVE, name: 'Activo' },
    ...overrides,
  }
}

function money(amount: string, formatted: string) {
  return { amount, currency: 'MXN', formatted }
}

function makeStats(): ChargeBatchSummary {
  return {
    expected: money('100000', '$1,000.00'),
    paid: money('40000', '$400.00'),
    outstanding: money('60000', '$600.00'),
    partial_paid: money('0', '$0.00'),
    unpaid_outstanding: money('60000', '$600.00'),
    total: 3,
    completed: 1,
    partial: 1,
    unpaid: 1,
    overdue: 0,
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/charge-batch/batch-1']}>{children}</MemoryRouter>
    </QueryClientProvider>
  )
  return render(<ChargeBatchDetail batchId='batch-1' />, { wrapper: Wrapper })
}

describe('ChargeBatchDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders a not-found state on a 404 and never fires the dependent queries', async () => {
    mockShow.mockRejectedValue(new ApiError('No encontrado', 404))
    renderDetail()

    expect(await screen.findByText('Cobro no encontrado')).toBeInTheDocument()
    // Ambas queries dependientes están gateadas en `isSuccess` → no corren en 404.
    expect(mockGetSettings).not.toHaveBeenCalled()
    expect(mockSummary).not.toHaveBeenCalled()
    expect(screen.getByRole('link', { name: 'Volver a cobros' })).toBeInTheDocument()
  })

  it('renders a generic error state with retry on a non-404 failure', async () => {
    mockShow.mockRejectedValue(new ApiError('Server error', 500))
    renderDetail()

    expect(await screen.findByText('No se pudo cargar la información')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reintentar' })).toBeInTheDocument()
    expect(mockGetSettings).not.toHaveBeenCalled()
    expect(mockSummary).not.toHaveBeenCalled()
  })

  it('renders the batch and fires the dependent queries once the main query succeeds', async () => {
    mockShow.mockResolvedValue(makeBatch())
    mockSummary.mockResolvedValue(makeStats())
    mockGetSettings.mockResolvedValue({})
    renderDetail()

    expect(await screen.findByText('Mantenimiento marzo 2026')).toBeInTheDocument()
    // El gating se abre tras el success → ambas dependientes corren.
    await waitFor(() => expect(mockSummary).toHaveBeenCalledWith('batch-1'))
    await waitFor(() => expect(mockGetSettings).toHaveBeenCalled())
  })
})
