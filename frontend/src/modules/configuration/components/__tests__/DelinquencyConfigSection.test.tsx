/**
 * Tests para DelinquencyConfigSection (design §8.1).
 *
 * Cubre: PUT envía solo el diff, el campo de dinero redondea pesos↔minor units
 * (5000 → 500000), y la copia de UI está en español.
 */
import { DelinquencyConfigSection } from '@/modules/configuration/components/DelinquencyConfigSection'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/api/apiClient', async (importActual) => {
  const actual = await importActual<typeof import('@/shared/api/apiClient')>()
  return { ...actual, apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }
})

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockGet = vi.mocked(apiClient.get)
const mockPut = vi.mocked(apiClient.put)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

const SETTINGS_RESPONSE = {
  data: {
    data: {
      restrictions_enabled: false,
      min_overdue_amount: { amount: '0', currency: 'MXN', formatted: '$0.00' },
      block_package_intake: false,
      block_amenities: false,
      block_visitor_access: false,
      notice_text: 'Tu unidad tiene adeudos vencidos.',
    },
  },
}

describe('DelinquencyConfigSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGet.mockResolvedValue(SETTINGS_RESPONSE)
    mockPut.mockResolvedValue({ data: {} })
  })

  it('renders Mexican-Spanish (tú) copy', async () => {
    render(<DelinquencyConfigSection canUpdate />, { wrapper: makeWrapper() })

    await waitFor(() =>
      expect(screen.getByText('Activar restricciones por morosidad')).toBeInTheDocument()
    )
    expect(screen.getByLabelText('Adeudo mínimo vencido (MXN)')).toBeInTheDocument()
    expect(screen.queryByText(/\bvos\b|tenés|querés|hacés|podés/i)).not.toBeInTheDocument()
  })

  it('converts 5000 pesos to 500000 minor units and sends only the edited keys on save', async () => {
    render(<DelinquencyConfigSection canUpdate />, { wrapper: makeWrapper() })

    const amountInput = await screen.findByLabelText('Adeudo mínimo vencido (MXN)')
    fireEvent.change(amountInput, { target: { value: '5000' } })

    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() =>
      expect(mockPut).toHaveBeenCalledWith('/config/delinquency-settings', {
        min_overdue_amount: 500000,
      })
    )
  })

  it('sends only the toggled key, never the whole form, when a checkbox is flipped', async () => {
    render(<DelinquencyConfigSection canUpdate />, { wrapper: makeWrapper() })

    const checkbox = await screen.findByRole('checkbox', {
      name: 'Reservaciones de amenidades',
    })
    fireEvent.click(checkbox)

    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() =>
      expect(mockPut).toHaveBeenCalledWith('/config/delinquency-settings', {
        block_amenities: true,
      })
    )
  })
})
