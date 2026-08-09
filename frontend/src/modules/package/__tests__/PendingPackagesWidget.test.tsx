/**
 * Tests para PendingPackagesWidget — widget del dashboard de paquetes pendientes.
 *
 * (1) Muestra el total de paquetes y el enlace "Ver paquetería" cuando total > 0.
 * (2) No renderiza nada cuando total es 0.
 * (3) No renderiza nada cuando el usuario no tiene permiso de staff/mesa
 *     (create.package / read.report_package).
 * (4) No renderiza nada ni dispara el fetch cuando el tenant no tiene el módulo
 *     Paquetería (add-on ausente del menú dinámico).
 */
import { PendingPackagesWidget } from '@/modules/package/components/PendingPackagesWidget'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { useModuleAvailable } from '@/shared/hooks/useModuleAvailable'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(),
}))

vi.mock('@/shared/hooks/useModuleAvailable', () => ({
  useModuleAvailable: vi.fn(),
}))

vi.mock('@/modules/package/services/PackageResource', () => ({
  PackageResource: {
    pendingCount: vi.fn(),
  },
}))

// ── Helpers ────────────────────────────────────────────────────────────────────

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

const mockUsePermissions = vi.mocked(usePermissions)
const mockUseModuleAvailable = vi.mocked(useModuleAvailable)
const mockPendingCount = vi.mocked(PackageResource.pendingCount)

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('PendingPackagesWidget', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Por defecto el tenant tiene el módulo Paquetería en el menú
    mockUseModuleAvailable.mockReturnValue(true)
  })

  it('muestra el total de paquetes y el enlace "Ver paquetería" cuando total > 0', async () => {
    mockUsePermissions.mockReturnValue(true)
    mockPendingCount.mockResolvedValue({ total: 3, d7_plus: 1 })

    render(<PendingPackagesWidget />, { wrapper: makeWrapper() })

    // Texto de conteo pluralizado
    expect(await screen.findByText(/3 paquetes pendientes/i)).toBeInTheDocument()
    // El enlace de CTA apunta a /package
    const link = screen.getByRole('link', { name: /ver paquetería/i })
    expect(link).toHaveAttribute('href', '/package')
    // Contrato con PackageReportController::pendingCount (create.package OR read.report_package)
    expect(mockUsePermissions).toHaveBeenCalledWith(['create.package', 'read.report_package'])
  })

  it('no renderiza nada cuando total es 0', async () => {
    mockUsePermissions.mockReturnValue(true)
    mockPendingCount.mockResolvedValue({ total: 0, d7_plus: 0 })

    const { container } = render(<PendingPackagesWidget />, { wrapper: makeWrapper() })

    // Esperar a que la query se resuelva antes de verificar la ausencia de contenido
    await waitFor(() => {
      expect(container.firstChild).toBeNull()
    })
  })

  it('no renderiza nada cuando el usuario no tiene permiso de staff/mesa', () => {
    mockUsePermissions.mockReturnValue(false)

    const { container } = render(<PendingPackagesWidget />, { wrapper: makeWrapper() })

    // La query no se ejecuta (enabled: false) → el widget retorna null de inmediato
    expect(container.firstChild).toBeNull()
  })

  it('no renderiza nada ni dispara el fetch cuando el tenant no tiene el módulo Paquetería', () => {
    mockUsePermissions.mockReturnValue(true)
    mockUseModuleAvailable.mockReturnValue(false)

    const { container } = render(<PendingPackagesWidget />, { wrapper: makeWrapper() })

    // Sin módulo en el menú del tenant: no hay render NI petición al backend
    // (evita el 403 "Módulo no habilitado para este tenant" en el dashboard)
    expect(container.firstChild).toBeNull()
    expect(mockPendingCount).not.toHaveBeenCalled()
  })
})
