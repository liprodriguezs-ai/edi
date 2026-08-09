/**
 * Tests for usePaymentActions.
 *
 * Focus: the motivo (reject/cancel reason) MUST NOT leak between payments. If the
 * user opens a dialog, types a reason, closes without confirming, then opens the
 * same action for ANOTHER payment, the textarea must start empty — otherwise the
 * wrong reason could be sent against the wrong payment (a money action).
 *
 * Also verifies the openers are referentially stable across re-renders, which is
 * what lets the list memoize its per-row action render.
 *
 * Strategy: renderHook with a QueryClientProvider wrapper (the hook uses
 * useMutation). PaymentResource / toast / download are mocked — these tests never
 * fire a mutation, they exercise the dialog open/close + reason state only.
 */
import { usePaymentActions } from '@/modules/payment/hooks/usePaymentActions'
import { PAYMENT_STATUS, type IPayment } from '@/modules/payment/schema'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

vi.mock('@/modules/payment/services/PaymentResource', () => ({
  PaymentResource: {
    validatePayment: vi.fn(),
    reject: vi.fn(),
    cancelPayment: vi.fn(),
  },
}))

vi.mock('@/shared/services/downloadMediaWithSignedUrl', () => ({
  downloadMediaWithSignedUrl: vi.fn(),
}))

function makePayment(id: string): IPayment {
  return {
    id,
    unit_id: 'unit-1',
    paid_at: '2026-03-14',
    total_amount: 150000,
    payment_method_id: 'pm-1',
    payment_status_id: PAYMENT_STATUS.PENDING_VALIDATION,
  } as IPayment
}

function setup() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )
  return renderHook(() => usePaymentActions({ onActionSuccess: vi.fn() }), { wrapper })
}

describe('usePaymentActions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('clears the reject motivo on close so it does not leak to the next payment', () => {
    const { result } = setup()

    act(() => result.current.requestReject(makePayment('pay-A')))
    act(() => result.current.dialog.setRejectReason('Comprobante ilegible de A'))
    expect(result.current.dialog.rejectReason).toBe('Comprobante ilegible de A')

    // Cerrar SIN confirmar (Escape / botón Cancelar) limpia el motivo.
    act(() => result.current.dialog.closeReject())
    expect(result.current.dialog.rejectReason).toBe('')

    // Reabrir Rechazar para OTRO pago: el textarea NO debe quedar precargado.
    act(() => result.current.requestReject(makePayment('pay-B')))
    expect(result.current.dialog.rejecting?.id).toBe('pay-B')
    expect(result.current.dialog.rejectReason).toBe('')
  })

  it('clears the cancel motivo on close so it does not leak to the next payment', () => {
    const { result } = setup()

    act(() => result.current.requestCancel(makePayment('pay-A')))
    act(() => result.current.dialog.setCancelReason('Pago duplicado de A'))
    expect(result.current.dialog.cancelReason).toBe('Pago duplicado de A')

    act(() => result.current.dialog.closeCancel())
    expect(result.current.dialog.cancelReason).toBe('')

    act(() => result.current.requestCancel(makePayment('pay-B')))
    expect(result.current.dialog.cancelling?.id).toBe('pay-B')
    expect(result.current.dialog.cancelReason).toBe('')
  })

  it('keeps the openers referentially stable across re-renders', () => {
    const { result } = setup()
    const firstRequestReject = result.current.requestReject
    const firstRequestValidate = result.current.requestValidate
    const firstRequestCancel = result.current.requestCancel
    const firstDownload = result.current.downloadReceipt

    // Forzar un re-render cambiando estado interno del hook.
    act(() => result.current.dialog.setRejectReason('x'))

    expect(result.current.requestReject).toBe(firstRequestReject)
    expect(result.current.requestValidate).toBe(firstRequestValidate)
    expect(result.current.requestCancel).toBe(firstRequestCancel)
    expect(result.current.downloadReceipt).toBe(firstDownload)
  })
})
