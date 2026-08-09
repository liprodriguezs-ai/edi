/**
 * Tests para PackagePickupModal — flujo de 3 pasos.
 *
 * (1) Cuando se abre sin paquete inicial muestra el Paso 1 (input de código).
 * (2) Tras verificar un código válido el modal avanza al Paso 2 (selección).
 */
import { PackagePickupModal } from '@/modules/package/PackagePickupModal'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock('@/modules/package/services/PackageResource', () => ({
  PackageResource: {
    pickupPolicy: vi.fn(),
    verifyCode: vi.fn(),
    handover: vi.fn(),
    search: vi.fn().mockResolvedValue({ data: [], meta: {} }),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// html5-qrcode and signature_pad use camera / canvas APIs unavailable in jsdom.
// Mock the component wrappers so the render tree is valid without browser access.
// html5-qrcode and signature_pad use camera / canvas APIs unavailable in jsdom.
// vi.mock factories are hoisted before imports so we cannot use JSX here — return null.
vi.mock('@/modules/package/components/QrScannerView', () => ({
  QrScannerView: vi.fn().mockReturnValue(null),
}))

vi.mock('@/modules/package/components/SignaturePadCanvas', () => ({
  SignaturePadCanvas: vi.fn().mockReturnValue(null),
}))

// ── Helpers ────────────────────────────────────────────────────────────────────

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

const mockPickupPolicy = vi.mocked(PackageResource.pickupPolicy)
const mockVerifyCode = vi.mocked(PackageResource.verifyCode)

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('PackagePickupModal', () => {
  const onClose = vi.fn()
  const onHandedOver = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    mockPickupPolicy.mockResolvedValue({
      requires_pickup_signature: false,
      requires_pickup_evidence: false,
      require_pickup_code: false,
    })
  })

  it('muestra el Paso 1 (Código) cuando se abre sin paquete inicial', async () => {
    render(
      <PackagePickupModal
        isOpen={true}
        initialPackage={null}
        onClose={onClose}
        onHandedOver={onHandedOver}
      />,
      { wrapper: makeWrapper() },
    )

    // El título del modal identifica el paso actual
    expect(await screen.findByText(/paso 1: código/i)).toBeInTheDocument()
    // El input de código está presente y accesible
    expect(screen.getByLabelText(/código de retiro del paquete/i)).toBeInTheDocument()
  })

  it('avanza al Paso 2 (Seleccionar paquetes) después de verificar un código válido', async () => {
    mockVerifyCode.mockResolvedValue({
      unit: { id: 'u1', number: '101', full_identifier: 'Casa 101' },
      packages: [
        {
          id: 'pkg-1',
          carrier: { id: 'c1', name: 'DHL' },
          status: { id: 's1', name: 'Listo' },
        },
      ],
    })

    render(
      <PackagePickupModal
        isOpen={true}
        initialPackage={null}
        onClose={onClose}
        onHandedOver={onHandedOver}
      />,
      { wrapper: makeWrapper() },
    )

    const input = await screen.findByLabelText(/código de retiro del paquete/i)

    await act(async () => {
      fireEvent.change(input, { target: { value: 'ABCD-1234' } })
    })

    // El botón "Verificar" se habilita cuando hay texto en el input
    const verifyBtn = screen.getByRole('button', { name: /verificar/i })

    await act(async () => {
      fireEvent.click(verifyBtn)
    })

    // Tras la mutación exitosa el modal avanza al Paso 2
    await waitFor(() => {
      expect(screen.getByText(/paso 2: seleccionar paquetes/i)).toBeInTheDocument()
    })
  })
})
