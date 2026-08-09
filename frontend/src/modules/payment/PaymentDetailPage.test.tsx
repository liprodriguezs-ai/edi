/**
 * Tests for PaymentDetailPage.
 *
 * Strategy: mock PaymentResource.show, downloadMediaWithSignedUrl and the toast.
 * Wrap with QueryClientProvider (useQuery) + MemoryRouter (useParams/useNavigate).
 * Assertions are data-driven (visible text / accessible names) to stay robust.
 */
import { PaymentDetailPage } from '@/modules/payment/PaymentDetailPage'
import { PAYMENT_STATUS, type IPayment } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { ApiError } from '@/shared/api/apiClient'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

vi.mock('@/modules/payment/services/PaymentResource', () => ({
  PaymentResource: {
    show: vi.fn(),
    validatePayment: vi.fn(),
    reject: vi.fn(),
    cancelPayment: vi.fn(),
  },
}))

// Las acciones se gatean por `update.payment`; el mock controla el permiso.
vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(() => false),
}))

const mockDownload = vi.fn()
vi.mock('@/shared/services/downloadMediaWithSignedUrl', () => ({
  downloadMediaWithSignedUrl: (...args: unknown[]) => mockDownload(...args),
}))

const mockShow = vi.mocked(PaymentResource.show)
const mockValidate = vi.mocked(PaymentResource.validatePayment)
const mockReject = vi.mocked(PaymentResource.reject)
const mockCancel = vi.mocked(PaymentResource.cancelPayment)
const mockUsePermissions = vi.mocked(usePermissions)

// ── Fixtures ─────────────────────────────────────────────────────────────────
function makeMedia(id: string, collection: string, fileName: string) {
  return {
    id,
    collection_name: collection,
    name: fileName,
    file_name: fileName,
    mime_type: 'application/pdf',
    disk: 'public',
    conversions_disk: 'public',
    size: 1024,
    manipulations: [],
    custom_properties: [],
    generated_conversions: [],
    responsive_images: [],
    order_column: 1,
    created_at: '2026-03-14',
    updated_at: '2026-03-14',
    model_type: 'payment',
    model_id: 'pay-1',
    original_url: '',
    preview_url: '',
  }
}

function makePayment(overrides: Partial<IPayment> = {}): IPayment {
  return {
    id: 'pay-1',
    unit_id: 'unit-1',
    paid_at: '2026-03-14',
    total_amount: { amount: '150000', currency: 'MXN', formatted: '$1,500.00' },
    payment_method_id: 'pm-1',
    payment_status_id: PAYMENT_STATUS.CONFIRMED,
    receipt_number: 'R-2026-000123',
    physical_receipt_number: 'F-9988',
    payment_status: { id: PAYMENT_STATUS.CONFIRMED, name: 'Confirmado' },
    payment_method: { id: 'pm-1', name: 'Transferencia' },
    unit: { id: 'unit-1', number: '12', full_identifier: 'Casa 12' },
    bank_account: { id: 'ba-1', name: 'Cuenta principal' },
    payer_user: { id: 'u-1', name: 'Juan Pérez' },
    applications: [
      {
        id: 'app-1',
        payment_id: 'pay-1',
        charge_id: 'ch-1',
        applied_amount: { amount: '100000', currency: 'MXN', formatted: '$1,000.00' },
        charge: {
          id: 'ch-1',
          unit_id: 'unit-1',
          expected_amount: 100000,
          paid_amount: 100000,
          charge_status_id: 'cs-1',
          concept: { id: 'co-1', name: 'Mantenimiento' } as never,
          batch: { id: 'b-1', year: 2026, month: 3 } as never,
        },
      },
    ],
    media: [],
    ...overrides,
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/payment/pay-1']}>
        <Routes>
          <Route path="/payment/:id" element={children} />
          <Route path="/payment" element={<div>Lista de pagos</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
  return render(<PaymentDetailPage />, { wrapper: Wrapper })
}

describe('PaymentDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows a loading state before the payment resolves (no resolved folio yet)', () => {
    // Never-resolving promise keeps the query pending.
    mockShow.mockReturnValue(new Promise<IPayment>(() => {}))
    renderDetail()

    // Page chrome renders, but the resolved folio is not shown while loading.
    expect(screen.getByText('Cobranza')).toBeInTheDocument()
    expect(screen.queryByText('R-2026-000123')).not.toBeInTheDocument()
  })

  it('renders folio, formatted amount, date, status, unit and FIFO applications on success', async () => {
    mockShow.mockResolvedValue(makePayment())
    renderDetail()

    expect(await screen.findByText('R-2026-000123')).toBeInTheDocument()
    // Money is shown via the serialized "formatted" field.
    expect(screen.getByText('$1,500.00')).toBeInTheDocument()
    // Date via the shared helper (real impl, deterministic).
    expect(screen.getByText(displayFormatDate('2026-03-14', 'mediumDate'))).toBeInTheDocument()
    expect(screen.getByText('Confirmado')).toBeInTheDocument()
    expect(screen.getByText('Casa 12')).toBeInTheDocument()
    expect(screen.getByText('Cuenta principal')).toBeInTheDocument()
    // FIFO application: charge concept + period + applied amount.
    expect(screen.getByText('Mantenimiento')).toBeInTheDocument()
    expect(screen.getByText('Mar 2026')).toBeInTheDocument()
    expect(screen.getByText('$1,000.00')).toBeInTheDocument()
  })

  it('shows the empty-applications message when there are no applications', async () => {
    mockShow.mockResolvedValue(makePayment({ applications: [] }))
    renderDetail()

    expect(await screen.findByText('R-2026-000123')).toBeInTheDocument()
    expect(screen.getByText(/aún no se aplicó a ningún cargo/i)).toBeInTheDocument()
  })

  it('hides download buttons when there is no media', async () => {
    mockShow.mockResolvedValue(makePayment({ media: [] }))
    renderDetail()

    await screen.findByText('R-2026-000123')
    expect(screen.queryByRole('button', { name: /recibo del sistema/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /comprobante subido/i })).not.toBeInTheDocument()
  })

  it('shows download buttons only for the media collections that exist', async () => {
    mockShow.mockResolvedValue(
      makePayment({
        media: [makeMedia('m-1', 'receipt', 'comprobante.pdf')],
      }),
    )
    renderDetail()

    await screen.findByText('R-2026-000123')
    expect(screen.getByRole('button', { name: /comprobante subido/i })).toBeInTheDocument()
    // No receipt_pdf media → no system-receipt button.
    expect(screen.queryByRole('button', { name: /recibo del sistema/i })).not.toBeInTheDocument()
  })

  it('renders a not-found state on a 404', async () => {
    mockShow.mockRejectedValue(new ApiError('No encontrado', 404))
    renderDetail()

    expect(await screen.findByText('Pago no encontrado')).toBeInTheDocument()
  })
})

/**
 * Acciones del detalle: deben reflejar el MISMO gating que la lista, pero como
 * el detalle no tiene pestaña, la visibilidad se decide por el `payment_status_id`
 * real + el permiso `update.payment`.
 */
describe('PaymentDetailPage — actions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUsePermissions.mockReturnValue(true)
  })

  function pendingPayment() {
    return makePayment({
      payment_status_id: PAYMENT_STATUS.PENDING_VALIDATION,
      payment_status: { id: PAYMENT_STATUS.PENDING_VALIDATION, name: 'Pendiente de validación' },
    })
  }

  it('shows validar/rechazar/cancelar for a pending payment with permission', async () => {
    mockShow.mockResolvedValue(pendingPayment())
    renderDetail()

    expect(await screen.findByRole('button', { name: 'Validar pago' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Rechazar pago' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancelar pago' })).toBeInTheDocument()
  })

  it('hides all actions without the update.payment permission', async () => {
    mockUsePermissions.mockReturnValue(false)
    mockShow.mockResolvedValue(pendingPayment())
    renderDetail()

    await screen.findByText('R-2026-000123')
    expect(screen.queryByRole('button', { name: 'Validar pago' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rechazar pago' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancelar pago' })).not.toBeInTheDocument()
  })

  it('shows only cancelar for a confirmed payment', async () => {
    mockShow.mockResolvedValue(makePayment()) // default = CONFIRMED
    renderDetail()

    expect(await screen.findByRole('button', { name: 'Cancelar pago' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Validar pago' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rechazar pago' })).not.toBeInTheDocument()
  })

  it('shows no actions for a cancelled payment', async () => {
    mockShow.mockResolvedValue(
      makePayment({
        payment_status_id: PAYMENT_STATUS.CANCELLED,
        payment_status: { id: PAYMENT_STATUS.CANCELLED, name: 'Cancelado' },
      }),
    )
    renderDetail()

    await screen.findByText('R-2026-000123')
    expect(screen.queryByRole('button', { name: 'Validar pago' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancelar pago' })).not.toBeInTheDocument()
  })

  it('validates and refetches the detail on success', async () => {
    mockShow.mockResolvedValue(pendingPayment())
    mockValidate.mockResolvedValue(makePayment())
    renderDetail()

    const validateBtn = await screen.findByRole('button', { name: 'Validar pago' })
    await act(async () => {
      fireEvent.click(validateBtn)
    })

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Validar y aplicar' })
    await act(async () => {
      fireEvent.click(confirm)
    })

    await waitFor(() => expect(mockValidate).toHaveBeenCalledWith('pay-1'))
    // El detalle se refetchea tras la acción (folio/estado pueden cambiar).
    await waitFor(() => expect(mockShow).toHaveBeenCalledTimes(2))
  })

  it('requires a motivo to reject, then calls reject and refetches', async () => {
    mockShow.mockResolvedValue(pendingPayment())
    mockReject.mockResolvedValue(makePayment())
    renderDetail()

    const rejectBtn = await screen.findByRole('button', { name: 'Rechazar pago' })
    await act(async () => {
      fireEvent.click(rejectBtn)
    })

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Rechazar pago' })
    expect(confirm).toBeDisabled() // sin motivo no se puede rechazar

    const textarea = within(dialog).getByPlaceholderText(/motivo del rechazo/i)
    await act(async () => {
      fireEvent.change(textarea, { target: { value: 'Comprobante ilegible' } })
    })
    expect(confirm).toBeEnabled()

    await act(async () => {
      fireEvent.click(confirm)
    })

    await waitFor(() => expect(mockReject).toHaveBeenCalledWith('pay-1', 'Comprobante ilegible'))
    await waitFor(() => expect(mockShow).toHaveBeenCalledTimes(2))
  })

  it('requires a 5+ char motivo to cancel, then calls cancel and refetches', async () => {
    mockShow.mockResolvedValue(makePayment()) // CONFIRMED → cancelar visible
    mockCancel.mockResolvedValue(
      makePayment({ payment_status_id: PAYMENT_STATUS.CANCELLED }),
    )
    renderDetail()

    const cancelBtn = await screen.findByRole('button', { name: 'Cancelar pago' })
    await act(async () => {
      fireEvent.click(cancelBtn)
    })

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Cancelar pago' })
    const textarea = within(dialog).getByPlaceholderText(/motivo de la cancelación/i)

    await act(async () => {
      fireEvent.change(textarea, { target: { value: 'err' } }) // 3 chars < 5
    })
    expect(confirm).toBeDisabled()

    await act(async () => {
      fireEvent.change(textarea, { target: { value: 'Pago duplicado' } })
    })
    expect(confirm).toBeEnabled()

    await act(async () => {
      fireEvent.click(confirm)
    })

    await waitFor(() => expect(mockCancel).toHaveBeenCalledWith('pay-1', 'Pago duplicado'))
    await waitFor(() => expect(mockShow).toHaveBeenCalledTimes(2))
  })
})
