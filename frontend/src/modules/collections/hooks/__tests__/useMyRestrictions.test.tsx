/**
 * Tests para useMyRestrictions (design §6.2 / §8.2).
 *
 * Cubre: shape del payload, y que `unitState(unitId)` es una consulta POR UNIDAD
 * (nunca un agregado) — la precisión multi-unidad que este cambio corrige.
 */
import { useMyRestrictions } from '@/modules/collections/hooks/useMyRestrictions'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/api/apiClient', async (importActual) => {
  const actual = await importActual<typeof import('@/shared/api/apiClient')>()
  return { ...actual, apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }
})

const mockGet = vi.mocked(apiClient.get)

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('useMyRestrictions', () => {
  beforeEach(() => vi.clearAllMocks())

  it('exposes notice and per-unit lookup from the flat GET /my-restrictions payload', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        restricted: true,
        notice: 'Tu unidad tiene adeudos vencidos…',
        units: [
          {
            unit_id: 'unit-a',
            restricted: false,
            features: { amenities: false, visitor_access: false },
          },
          {
            unit_id: 'unit-b',
            restricted: true,
            features: { amenities: true, visitor_access: true },
            overdue_balance: { amount: '500000', currency: 'MXN', formatted: '$5,000.00' },
          },
        ],
      },
    })

    const { result } = renderHook(() => useMyRestrictions(), { wrapper: makeWrapper() })

    await waitFor(() => expect(result.current.notice).toBe('Tu unidad tiene adeudos vencidos…'))

    expect(mockGet).toHaveBeenCalledWith('/my-restrictions')
    // Never an ANY-aggregate: unit A stays unrestricted even though B is restricted.
    expect(result.current.unitState('unit-a')).toEqual(
      expect.objectContaining({
        restricted: false,
        features: { amenities: false, visitor_access: false },
      })
    )
    expect(result.current.unitState('unit-b')).toEqual(
      expect.objectContaining({
        restricted: true,
        features: { amenities: true, visitor_access: true },
        overdue_balance: { amount: '500000', currency: 'MXN', formatted: '$5,000.00' },
      })
    )
    expect(result.current.unitState('unit-unknown')).toBeUndefined()
  })
})
