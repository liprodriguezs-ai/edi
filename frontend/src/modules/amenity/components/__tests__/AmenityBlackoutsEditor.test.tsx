/**
 * Smoke tests para AmenityBlackoutsEditor: lista bloqueos existentes y agrega
 * uno nuevo contra `/amenity_blackout` (mockeado).
 */
import { AmenityBlackoutsEditor } from '@/modules/amenity/components/AmenityBlackoutsEditor'
import type { IAmenityBlackout } from '@/modules/amenity/schema'
import { AmenityBlackoutResource } from '@/modules/amenity/services/AmenityBlackoutResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/amenity/services/AmenityBlackoutResource', () => ({
  AmenityBlackoutResource: {
    create: vi.fn().mockResolvedValue({ id: 'blackout-new' }),
    remove: vi.fn().mockResolvedValue('blackout-1'),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const existingBlackout: IAmenityBlackout = {
  id: 'blackout-1',
  amenity_id: 'amenity-1',
  start_date: '2026-08-01',
  end_date: '2026-08-03',
  reason: 'Mantenimiento',
}

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('AmenityBlackoutsEditor', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders existing blackouts', () => {
    render(
      <AmenityBlackoutsEditor
        amenityId='amenity-1'
        blackouts={[existingBlackout]}
        disabled={false}
      />,
      { wrapper: makeWrapper() }
    )

    expect(screen.getByText(/Mantenimiento/)).toBeInTheDocument()
  })

  it('shows the empty state when there are no blackouts', () => {
    render(<AmenityBlackoutsEditor amenityId='amenity-1' blackouts={[]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByText('Sin bloqueos configurados.')).toBeInTheDocument()
  })

  it('creates a new blackout on submit', async () => {
    render(<AmenityBlackoutsEditor amenityId='amenity-1' blackouts={[]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-09-01' } })
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-09-05' } })
    fireEvent.click(screen.getByRole('button', { name: /Agregar bloqueo/ }))

    await waitFor(() => expect(AmenityBlackoutResource.create).toHaveBeenCalledTimes(1))
    expect(AmenityBlackoutResource.create).toHaveBeenCalledWith(
      expect.objectContaining({
        amenity_id: 'amenity-1',
        start_date: '2026-09-01',
        end_date: '2026-09-05',
      })
    )
  })
})
