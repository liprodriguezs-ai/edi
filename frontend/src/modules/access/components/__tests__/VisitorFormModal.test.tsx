/**
 * Tests for VisitorFormModal's delinquency notice (design §8.2, R-DELQ-11).
 *
 * Cubre: la restricción es POR UNIDAD seleccionada, el aviso vive dentro del
 * modal (nunca oculta el combobox), el submit se bloquea SOLO mientras la
 * unidad restringida está elegida, y `useMyRestrictions` no se dispara si el
 * modal no está abierto (regla de infra-cost).
 */
import { VisitorFormModal } from '@/modules/access/components/VisitorFormModal'
import { VisitorResource } from '@/modules/access/services/AccessResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/access/services/AccessResource', () => ({
  VisitorResource: { create: vi.fn() },
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
    useCatalogs: () => ({
      catalogs: {
        [actual.CatalogType.VISITOR_MOTIVE]: [{ id: 'motive-1', name: 'Visita familiar' }],
        [actual.CatalogType.QR_TYPE]: [{ id: 'qrtype-1', name: 'Único uso' }],
      },
    }),
  }
})

// El modal renderiza UNO de los dos selectores de unidad según quién sea el
// usuario: staff → UnitCombobox (busca todo el condominio), residente →
// ResidentUnitField (solo sus unidades). Stubeamos AMBOS con los mismos dos
// botones: así los tests de morosidad valen para cualquiera de las dos ramas,
// y el `data-testid` deja verificar cuál se eligió.
vi.mock('@/modules/unit/components/UnitCombobox', () => ({
  UnitCombobox: ({
    onSelect,
  }: {
    onSelect: (_unit: { id: string; full_identifier: string }) => void
  }) => (
    <div data-testid='unit-combobox'>
      <button type='button' onClick={() => onSelect({ id: 'unit-a', full_identifier: 'Casa 1' })}>
        Elegir Casa 1
      </button>
      <button type='button' onClick={() => onSelect({ id: 'unit-b', full_identifier: 'Casa 2' })}>
        Elegir Casa 2
      </button>
    </div>
  ),
}))

vi.mock('@/modules/access/components/ResidentUnitField', () => ({
  ResidentUnitField: ({ onChange }: { onChange: (_unitId: string) => void }) => (
    <div data-testid='resident-unit-field'>
      <button type='button' onClick={() => onChange('unit-a')}>
        Elegir Casa 1
      </button>
      <button type='button' onClick={() => onChange('unit-b')}>
        Elegir Casa 2
      </button>
    </div>
  ),
}))

// `read.access_log` es el MISMO criterio que usa el backend (CallerBelongsToUnit)
// para permitir apuntar a una unidad ajena. Default: residente (sin el permiso).
const mockUsePermissions = vi.fn((_key: string | string[]): boolean => false)
vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: (key: string | string[]) => mockUsePermissions(key),
}))

const mockUseMyRestrictions = vi.fn()
vi.mock('@/modules/collections/hooks/useMyRestrictions', () => ({
  useMyRestrictions: () => mockUseMyRestrictions(),
}))

const mockCreate = vi.mocked(VisitorResource.create)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('VisitorFormModal — delinquency notice', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUsePermissions.mockReturnValue(false)
    mockUseMyRestrictions.mockReturnValue({
      notice: 'Tu unidad tiene adeudos vencidos.',
      unitState: (unitId: string) =>
        unitId === 'unit-b'
          ? {
              unit_id: 'unit-b',
              restricted: true,
              features: { amenities: true, visitor_access: true },
              overdue_balance: { amount: '500000', currency: 'MXN', formatted: '$5,000.00' },
            }
          : {
              unit_id: 'unit-a',
              restricted: false,
              features: { amenities: false, visitor_access: false },
            },
      isLoading: false,
    })
  })

  it('does not call useMyRestrictions when the modal is closed (infra-cost guard)', () => {
    render(<VisitorFormModal isOpen={false} onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })
    expect(mockUseMyRestrictions).not.toHaveBeenCalled()
  })

  it('always opens with the unit field visible and the submit button enabled', () => {
    render(<VisitorFormModal isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByText('Unidad que visita')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Registrar visita' })).not.toBeDisabled()
  })

  it('shows the notice and blocks submit only when the restricted unit is selected', () => {
    render(<VisitorFormModal isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    // Unit A (current): no notice, submit stays enabled.
    fireEvent.click(screen.getByText('Elegir Casa 1'))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Registrar visita' })).not.toBeDisabled()

    // Unit B (overdue): notice appears, submit is blocked — never silently swallowed.
    fireEvent.click(screen.getByText('Elegir Casa 2'))
    expect(screen.getByRole('alert')).toHaveTextContent('$5,000.00')
    expect(screen.getByRole('alert')).toHaveTextContent('Tu unidad tiene adeudos vencidos.')
    expect(screen.getByRole('button', { name: 'Registrar visita' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Registrar visita' }))
    expect(mockCreate).not.toHaveBeenCalled()

    // Switching back to A lifts the block — precision is PER unit, not an aggregate.
    fireEvent.click(screen.getByText('Elegir Casa 1'))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Registrar visita' })).not.toBeDisabled()
  })
})

/**
 * El alcance del campo de unidad se decide por el USUARIO (permiso
 * `read.access_log`, el mismo criterio que CallerBelongsToUnit en el backend),
 * NUNCA por la página que abre el modal. Se probó atarlo al call site y dejó un
 * agujero: el residente también llega a la lista de Visitantes, y ahí recibía el
 * buscador de todo el condominio — unidades que el backend le iba a rechazar.
 */
describe('VisitorFormModal — alcance del campo de unidad', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseMyRestrictions.mockReturnValue({
      notice: null,
      unitState: () => undefined,
      isLoading: false,
    })
  })

  it('un residente (sin read.access_log) solo recibe sus propias unidades', () => {
    mockUsePermissions.mockReturnValue(false)
    render(<VisitorFormModal isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByTestId('resident-unit-field')).toBeInTheDocument()
    expect(screen.queryByTestId('unit-combobox')).not.toBeInTheDocument()
  })

  it('staff (con read.access_log) recibe el buscador de todo el condominio', () => {
    mockUsePermissions.mockReturnValue(true)
    render(<VisitorFormModal isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByTestId('unit-combobox')).toBeInTheDocument()
    expect(screen.queryByTestId('resident-unit-field')).not.toBeInTheDocument()
  })

  it('consulta exactamente el permiso que el backend usa para permitir unidades ajenas', () => {
    render(<VisitorFormModal isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    expect(mockUsePermissions).toHaveBeenCalledWith('read.access_log')
  })
})
