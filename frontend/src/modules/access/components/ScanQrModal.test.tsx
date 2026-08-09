/**
 * Characterization tests for ScanQrModal.
 *
 * Strategy: mock AccessLogResource.scanQr and the toast hook.
 * Use QueryClientProvider wrapper because the component uses useMutation.
 * Rely on data-driven DOM queries (role, label) to stay implementation-agnostic.
 */
import { ScanQrModal } from '@/modules/access/components/ScanQrModal'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { ApiError } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ──────────────────────────────────────────────────────────────────
vi.mock('@/shared/hooks/useToast', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
}))

vi.mock('@/modules/access/services/AccessResource', () => ({
  AccessLogResource: {
    scanQr: vi.fn(),
  },
}))

// ── Helpers ────────────────────────────────────────────────────────────────
function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

const mockScanQr = vi.mocked(AccessLogResource.scanQr)

describe('ScanQrModal — characterization', () => {
  const onClose = vi.fn()
  const onScanned = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── (a) Enter key submits the code ─────────────────────────────────────
  it('submits when the user presses Enter in the input', async () => {
    mockScanQr.mockResolvedValue({
      valid: true,
      visitor: { id: 'v1', name: 'Juan Pérez' } as never,
    })

    render(
      <ScanQrModal isOpen={true} onClose={onClose} onScanned={onScanned} />,
      { wrapper: makeWrapper() }
    )

    const input = screen.getByLabelText(/código del qr/i)
    await act(async () => {
      fireEvent.change(input, { target: { value: 'QR-TOKEN-ABC123' } })
    })

    await act(async () => {
      fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' })
    })

    await waitFor(() => expect(mockScanQr).toHaveBeenCalledWith('QR-TOKEN-ABC123'))
  })

  // ── (b) Success → onScanned + toast + modal closes ──────────────────────
  it('calls onScanned, confirms via toast and closes after a successful scan', async () => {
    mockScanQr.mockResolvedValue({
      valid: true,
      visitor: { id: 'v1', name: 'María López' } as never,
    })

    render(
      <ScanQrModal isOpen={true} onClose={onClose} onScanned={onScanned} />,
      { wrapper: makeWrapper() }
    )

    const input = screen.getByLabelText(/código del qr/i)
    await act(async () => {
      fireEvent.change(input, { target: { value: 'VALID-QR' } })
      fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' })
    })

    await waitFor(() => expect(onScanned).toHaveBeenCalledTimes(1))
    // On success the modal confirms with a success toast (carrying the visitor
    // name) and closes — there is no in-DOM result panel on the happy path.
    expect(toast.success).toHaveBeenCalledWith(expect.stringMatching(/maría lópez/i))
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  // ── (c) API error → error message visible, onScanned NOT called ─────────
  it('shows error reason and does NOT call onScanned on API error', async () => {
    const scanError = new ApiError('QR expirado', 422, { reason: 'QR expirado o ya usado' })
    mockScanQr.mockRejectedValue(scanError)

    render(
      <ScanQrModal isOpen={true} onClose={onClose} onScanned={onScanned} />,
      { wrapper: makeWrapper() }
    )

    const input = screen.getByLabelText(/código del qr/i)
    await act(async () => {
      fireEvent.change(input, { target: { value: 'EXPIRED-QR' } })
      fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' })
    })

    await waitFor(() => {
      expect(screen.getByText(/qr expirado o ya usado/i)).toBeInTheDocument()
    })

    expect(onScanned).not.toHaveBeenCalled()
  })
})
