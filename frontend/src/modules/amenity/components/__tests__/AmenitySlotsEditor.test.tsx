/**
 * Smoke tests para AmenitySlotsEditor: lista horarios existentes y agrega uno
 * nuevo contra `/amenity_slot` (mockeado).
 */
import { AmenitySlotsEditor } from '@/modules/amenity/components/AmenitySlotsEditor'
import type { IAmenitySlot } from '@/modules/amenity/schema'
import { AmenitySlotResource } from '@/modules/amenity/services/AmenitySlotResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/amenity/services/AmenitySlotResource', () => ({
  AmenitySlotResource: {
    create: vi.fn().mockResolvedValue({ id: 'slot-new' }),
    update: vi.fn(),
    remove: vi.fn().mockResolvedValue('slot-1'),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const existingSlot: IAmenitySlot = {
  id: 'slot-1',
  amenity_id: 'amenity-1',
  weekday: 2,
  start_time: '18:00:00',
  end_time: '19:00:00',
  capacity_override: null,
}

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('AmenitySlotsEditor', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders existing slots', () => {
    render(<AmenitySlotsEditor amenityId='amenity-1' slots={[existingSlot]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByText(/Mar · 18:00–19:00/)).toBeInTheDocument()
  })

  it('shows the empty state when there are no slots', () => {
    render(<AmenitySlotsEditor amenityId='amenity-1' slots={[]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByText('Sin horarios configurados.')).toBeInTheDocument()
  })

  it('creates a new slot on submit', async () => {
    render(<AmenitySlotsEditor amenityId='amenity-1' slots={[]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    fireEvent.change(screen.getByLabelText('Inicio'), { target: { value: '10:00' } })
    fireEvent.change(screen.getByLabelText('Fin'), { target: { value: '11:00' } })
    fireEvent.click(screen.getByRole('button', { name: /Agregar horario/ }))

    await waitFor(() => expect(AmenitySlotResource.create).toHaveBeenCalledTimes(1))
    expect(AmenitySlotResource.create).toHaveBeenCalledWith(
      expect.objectContaining({ amenity_id: 'amenity-1', start_time: '10:00', end_time: '11:00' })
    )
  })
})
