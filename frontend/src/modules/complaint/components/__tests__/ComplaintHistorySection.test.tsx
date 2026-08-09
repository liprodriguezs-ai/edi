/**
 * Smoke tests para ComplaintHistorySection.
 *
 * (1) Colapsada por default — NO dispara el fetch al montar (infra-cost rule).
 * (2) Al expandir, dispara el fetch y renderiza el timeline traducido.
 */
import { ComplaintHistorySection } from '@/modules/complaint/components/ComplaintHistorySection'
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/complaint/services/ComplaintResource', () => ({
  ComplaintResource: {
    history: vi.fn(),
  },
}))

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

const mockHistory = vi.mocked(ComplaintResource.history)

describe('ComplaintHistorySection', () => {
  beforeEach(() => vi.clearAllMocks())

  it('no dispara el fetch de historial mientras está colapsada', () => {
    render(<ComplaintHistorySection complaintId='complaint-1' />, { wrapper: makeWrapper() })

    expect(screen.getByText('Ver historial')).toBeInTheDocument()
    expect(mockHistory).not.toHaveBeenCalled()
  })

  it('dispara el fetch y renderiza el timeline traducido al expandir', async () => {
    mockHistory.mockResolvedValue([
      {
        id: 'audit-1',
        event: 'created',
        user_name: 'Presidente Test',
        created_at: '2026-07-01T10:00:00Z',
        changes: [],
      },
      {
        id: 'audit-2',
        event: 'updated',
        user_name: 'Presidente Test',
        created_at: '2026-07-02T10:00:00Z',
        changes: [
          { field: 'complaint_status_id', label: 'Estado', from: 'Registrada', to: 'En revisión' },
        ],
      },
    ])

    render(<ComplaintHistorySection complaintId='complaint-1' />, { wrapper: makeWrapper() })

    fireEvent.click(screen.getByText('Ver historial'))

    await waitFor(() => expect(mockHistory).toHaveBeenCalledWith('complaint-1'))

    expect(await screen.findByText('Incidencia registrada')).toBeInTheDocument()
    expect(screen.getByText('Estado:')).toBeInTheDocument()
    expect(screen.getByText('Registrada')).toBeInTheDocument()
    expect(screen.getByText('En revisión')).toBeInTheDocument()
  })
})
