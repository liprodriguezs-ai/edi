/**
 * Tests para MyPackagesPage (vista de residente).
 *
 * (1) Muestra los paquetes pendientes del residente con el botón "Ver código de retiro".
 * (2) Al hacer click en el botón se abre el diálogo con el código de retiro.
 */
import { MyPackagesPage } from '@/modules/package/MyPackagesPage'
import type { IMyPackage } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock('@/modules/package/services/PackageResource', () => ({
  PackageResource: {
    myPackages: vi.fn(),
    pickupPolicy: vi.fn().mockResolvedValue({
      requires_pickup_signature: false,
      requires_pickup_evidence: false,
      require_pickup_code: false,
      liability_disclaimer: null,
    }),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ── Helpers ────────────────────────────────────────────────────────────────────

function makePackage(overrides: Partial<IMyPackage> = {}): IMyPackage {
  return {
    id: 'pkg-1',
    unit_id: 'unit-1',
    package_status_id: 'a2001001-0000-4000-8000-000000000002',
    pickup_code: 'ABCD-1234',
    carrier: { id: 'c1', name: 'DHL' },
    // key: 'ready' — required so the pending/history split identifies this as a pending package.
    // The "Ver código de retiro" button also requires status.key === 'ready' && pickup_code.
    status: { id: 's1', name: 'Listo', key: 'ready' },
    received_at: '2026-06-28T10:00:00Z',
    ...overrides,
  }
}

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

const mockMyPackages = vi.mocked(PackageResource.myPackages)

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('MyPackagesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // pending query (no params) returns ready packages; history query returns empty
    mockMyPackages.mockImplementation((params) => {
      if (params?.history) return Promise.resolve([])
      return Promise.resolve([makePackage()])
    })
  })

  it('muestra paquetes pendientes y el botón "Ver código de retiro"', async () => {
    render(<MyPackagesPage />, { wrapper: makeWrapper() })

    // La sección "Pendientes de retiro" aparece cuando hay paquetes con pickup_code
    expect(await screen.findByText('Pendientes de retiro (1)')).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: /ver código de retiro/i }),
    ).toBeInTheDocument()
  })

  it('abre el diálogo con el código de retiro al hacer click en el botón', async () => {
    render(<MyPackagesPage />, { wrapper: makeWrapper() })

    const btn = await screen.findByRole('button', { name: /ver código de retiro/i })
    fireEvent.click(btn)

    // El diálogo se abre y muestra el título y el código en formato mono
    expect(await screen.findByText('Código de retiro')).toBeInTheDocument()
    expect(screen.getByText('ABCD-1234')).toBeInTheDocument()
  })
})
