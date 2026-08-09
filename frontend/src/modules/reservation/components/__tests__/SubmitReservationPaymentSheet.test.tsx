/**
 * Tests for SubmitReservationPaymentSheet (Slice C — cash restriction).
 *
 * Residents cannot pay amenities in cash: cash is admin-register-only via
 * `RegisterReservationPaymentSheet`. This sheet must:
 *  - never offer CASH as a método de pago option (filtered client-side),
 *  - default `payment_method_id` to TRANSFER, not CASH,
 *  - always require a comprobante, regardless of the selected method.
 */
import { SubmitReservationPaymentSheet } from '@/modules/reservation/components/SubmitReservationPaymentSheet'
import { PAYMENT_METHOD } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import type { IAmenityReservation } from '@/modules/reservation/schema'
import { toast } from '@/shared/hooks/useToast'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/payment/services/PaymentResource', () => ({
  PaymentResource: { submit: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/shared/hooks/useCatalogs', async () => {
  const actual = await vi.importActual<typeof import('@/shared/hooks/useCatalogs')>(
    '@/shared/hooks/useCatalogs'
  )
  return {
    ...actual,
    useLazyCatalogs: () => ({
      catalogs: {
        [actual.CatalogType.PAYMENT_METHOD]: [
          { id: PAYMENT_METHOD.CASH, name: 'Efectivo' },
          { id: PAYMENT_METHOD.TRANSFER, name: 'Transferencia' },
          { id: PAYMENT_METHOD.DEPOSIT, name: 'Depósito' },
          { id: PAYMENT_METHOD.CHECK, name: 'Cheque' },
        ],
      },
      enable: vi.fn(),
    }),
  }
})

const mockSubmit = vi.mocked(PaymentResource.submit)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

const reservation: IAmenityReservation = {
  id: 'res-1',
  amenity_id: 'amenity-1',
  unit_id: 'unit-1',
  reservation_date: '2026-07-15',
  reservation_status_id: 'status-pending-payment',
  cost_amount: 50000,
  deposit_amount: null,
  amount_outstanding: 50000,
}

const amenity = {
  bank_account_id: 'account-1',
  bank_account: { id: 'account-1', name: 'Cuenta amenidad' },
}

function attachReceipt() {
  const file = new File(['dummy'], 'comprobante.png', { type: 'image/png' })
  fireEvent.change(screen.getByLabelText('Comprobante'), { target: { files: [file] } })
}

function submitForm() {
  fireEvent.click(screen.getByRole('button', { name: /Enviar pago/ }))
}

/** Radix's RadioGroupItem renders as a `<button role="radio">` inside the wrapping `<label>`. */
function selectPaymentMethod(name: string) {
  const label = screen.getByText(name).closest('label')
  const control = label?.querySelector('button')
  if (!control) throw new Error(`Radio control for "${name}" not found`)
  fireEvent.click(control)
}

describe('SubmitReservationPaymentSheet', () => {
  beforeEach(() => vi.clearAllMocks())

  it('does not offer Efectivo as a método de pago option', () => {
    render(
      <SubmitReservationPaymentSheet
        isOpen
        onClose={vi.fn()}
        reservation={reservation}
        amenity={amenity}
        onSubmitted={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    )

    expect(screen.queryByText('Efectivo')).not.toBeInTheDocument()
    expect(screen.getByText('Transferencia')).toBeInTheDocument()
    expect(screen.getByText('Depósito')).toBeInTheDocument()
    expect(screen.getByText('Cheque')).toBeInTheDocument()
  })

  it('defaults to Transferencia and submits with it when a comprobante is attached', async () => {
    mockSubmit.mockResolvedValueOnce({
      id: 'payment-1',
      unit_id: 'unit-1',
      paid_at: '2026-07-15',
      total_amount: 50000,
      payment_method_id: PAYMENT_METHOD.TRANSFER,
      payment_status_id: 'pending-validation',
    })

    render(
      <SubmitReservationPaymentSheet
        isOpen
        onClose={vi.fn()}
        reservation={reservation}
        amenity={amenity}
        onSubmitted={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    )

    attachReceipt()
    submitForm()

    await waitFor(() =>
      expect(mockSubmit).toHaveBeenCalledWith(
        expect.objectContaining({ payment_method_id: PAYMENT_METHOD.TRANSFER })
      )
    )
    expect(toast.error).not.toHaveBeenCalled()
  })

  it('requires a comprobante regardless of the selected method', async () => {
    render(
      <SubmitReservationPaymentSheet
        isOpen
        onClose={vi.fn()}
        reservation={reservation}
        amenity={amenity}
        onSubmitted={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    )

    // Switch away from the default (Transferencia) to Depósito — still no receipt attached.
    selectPaymentMethod('Depósito')
    submitForm()

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('El comprobante es obligatorio'))
    expect(mockSubmit).not.toHaveBeenCalled()
  })
})

describe('SubmitReservationPaymentSheet — Slice E polish', () => {
  beforeEach(() => vi.clearAllMocks())

  const reservationWithDeposit: IAmenityReservation = {
    id: 'res-1',
    amenity_id: 'amenity-1',
    unit_id: 'unit-1',
    reservation_date: '2026-07-15',
    reservation_status_id: 'status-pending-payment',
    cost_amount: 50000,
    deposit_amount: 20000,
    amount_outstanding: 50000,
  }

  it('defaults a balance (second) payment to the full outstanding, not the deposit', () => {
    // Nothing settled equals a first payment (outstanding === cost snapshot);
    // here outstanding (30000) is already below the total (50000) — a deposit
    // was already applied, so this is the balance payment.
    const balancePaymentReservation: IAmenityReservation = {
      ...reservationWithDeposit,
      amount_outstanding: 30000,
    }

    render(
      <SubmitReservationPaymentSheet
        isOpen
        onClose={vi.fn()}
        reservation={balancePaymentReservation}
        amenity={amenity}
        onSubmitted={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    )

    expect(screen.getByLabelText('Monto')).toHaveValue(300)
  })

  it('blocks a first payment below the deposit minimum with the same amount shown in the header', async () => {
    render(
      <SubmitReservationPaymentSheet
        isOpen
        onClose={vi.fn()}
        reservation={reservationWithDeposit}
        amenity={amenity}
        onSubmitted={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    )

    attachReceipt()
    fireEvent.change(screen.getByLabelText('Monto'), { target: { value: '100' } })
    submitForm()

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        'El primer pago debe cubrir al menos el depósito de $200.00'
      )
    )
    expect(mockSubmit).not.toHaveBeenCalled()
  })
})
