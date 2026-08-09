/**
 * Tests for ReserveAmenityWizard's delinquency notice (design §8.2, R-DELQ-11).
 *
 * Cubre: la restricción es POR UNIDAD (nunca un agregado), el aviso solo
 * aparece cuando se elige la unidad restringida, "Siguiente" se bloquea SOLO
 * mientras esa unidad está seleccionada, y `useMyRestrictions` no se dispara
 * si el wizard no está abierto (regla de infra-cost).
 */
import { ReserveAmenityWizard } from '@/modules/reservation/components/ReserveAmenityWizard'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import type { IAmenity } from '@/modules/amenity/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import type { IUnit } from '@/modules/unit/schema'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/unit/services/UnitResource', () => ({
  UnitResource: { my: vi.fn() },
}))

vi.mock('@/modules/reservation/services/AmenityReservationResource', () => ({
  AmenityReservationResource: { create: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockUseMyRestrictions = vi.fn()
vi.mock('@/modules/collections/hooks/useMyRestrictions', () => ({
  useMyRestrictions: () => mockUseMyRestrictions(),
}))

const mockMy = vi.mocked(UnitResource.my)
const mockCreate = vi.mocked(AmenityReservationResource.create)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const amenity: IAmenity = {
  id: 'amenity-1',
  name: 'Salón de fiestas',
  capacity: 20,
  available_weekdays: [0, 1, 2, 3, 4, 5, 6],
  reservation_mode_id: 'mode-flexible',
  has_cost: false,
}

const unitA: IUnit = {
  id: 'unit-a',
  unit_type_id: 'type-1',
  unit_status_id: 'status-1',
  full_identifier: 'Casa 1',
}

const unitB: IUnit = {
  id: 'unit-b',
  unit_type_id: 'type-1',
  unit_status_id: 'status-1',
  full_identifier: 'Casa 2',
}

/** UnitStep renders `<input type="radio">` nested inside its `<label>` (implicit association). */
function selectUnit(label: string) {
  const control = screen.getByText(label).closest('label')?.querySelector('input')
  if (!control) throw new Error(`Radio control for "${label}" not found`)
  fireEvent.click(control)
}

describe('ReserveAmenityWizard — delinquency notice', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMy.mockResolvedValue([unitA, unitB])
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

  it('does not call useMyRestrictions when the wizard is closed (infra-cost guard)', () => {
    render(<ReserveAmenityWizard isOpen={false} onClose={vi.fn()} amenity={amenity} />, {
      wrapper: makeWrapper(),
    })
    expect(mockUseMyRestrictions).not.toHaveBeenCalled()
  })

  it('always reaches the unit picker, with both units selectable — nothing hidden or disabled', async () => {
    render(<ReserveAmenityWizard isOpen onClose={vi.fn()} amenity={amenity} />, {
      wrapper: makeWrapper(),
    })

    expect(await screen.findByText('Casa 1')).toBeInTheDocument()
    expect(screen.getByText('Casa 2')).toBeInTheDocument()
    const radios = screen.getAllByRole('radio')
    expect(radios).toHaveLength(2)
    radios.forEach((radio) => expect(radio).not.toBeDisabled())
  })

  it('shows the notice and blocks "Siguiente" only when the restricted unit is selected', async () => {
    render(<ReserveAmenityWizard isOpen onClose={vi.fn()} amenity={amenity} />, {
      wrapper: makeWrapper(),
    })

    await screen.findByText('Casa 1')

    // Unit A (current): proceeds normally, no notice.
    selectUnit('Casa 1')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Siguiente' })).not.toBeDisabled()

    // Unit B (overdue): notice appears, "Siguiente" is blocked — never advances
    // past the picker while B is selected.
    selectUnit('Casa 2')
    expect(await screen.findByRole('alert')).toHaveTextContent('$5,000.00')
    expect(screen.getByRole('alert')).toHaveTextContent('Tu unidad tiene adeudos vencidos.')
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled()

    // Switching back to A lifts the block — precision is PER unit, not an aggregate.
    selectUnit('Casa 1')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Siguiente' })).not.toBeDisabled()

    expect(mockCreate).not.toHaveBeenCalled()
  })
})
