/**
 * Tests for usePackageCatalogOptions' restricted-units lazy query (design §8.3).
 *
 * Cubre: la query de unidades restringidas SOLO se dispara cuando el
 * dropdown de unidad se habilita (mismo trigger lazy que `enableUnits`),
 * nunca al montar — regla de infra-cost.
 */
import { usePackageCatalogOptions } from '@/modules/package/hooks/usePackageCatalogOptions'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/api/apiClient', async (importActual) => {
  const actual = await importActual<typeof import('@/shared/api/apiClient')>()
  return { ...actual, apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }
})

const mockGet = vi.mocked(apiClient.get)
const mockPost = vi.mocked(apiClient.post)

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('usePackageCatalogOptions — restricted units', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockPost.mockResolvedValue({ data: { data: { unit: [] } } })
    mockGet.mockResolvedValue({ data: { unit_ids: ['unit-b'] } })
  })

  it('does not fetch restricted units before the unit dropdown is enabled', () => {
    renderHook(() => usePackageCatalogOptions(), { wrapper: makeWrapper() })
    expect(mockGet).not.toHaveBeenCalled()
  })

  it('fetches restricted units once the unit dropdown is enabled (rides the existing lazy trigger)', async () => {
    const { result } = renderHook(() => usePackageCatalogOptions(), { wrapper: makeWrapper() })

    act(() => result.current.enableUnits())

    await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/package/restricted-units'))
    await waitFor(() => expect(result.current.restrictedUnitIds.has('unit-b')).toBe(true))
    expect(result.current.restrictedUnitIds.has('unit-a')).toBe(false)
  })
})
