/**
 * Characterization tests for useBaseList.
 *
 * Strategy: real store created per-test with createEntityStore (reset() between cases),
 * resource.search mocked to capture params and return controlled responses.
 * These tests characterize the CURRENT behavior — do not change them in plan 030
 * unless intentionally changing the contract.
 */
import { createEntityStore } from '@/shared/store/EntityStore'
import type { Filter, Sort } from '@/shared/interfaces/list.types'
import type { IPaginationMeta } from '@/shared/interfaces/list.types'
import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBaseList } from './useBaseList'

// Mock useToast to avoid sileo DOM dependency
vi.mock('@/shared/hooks/useToast', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
}))

interface ITestEntity {
  id: string
  name?: string
  [key: string]: unknown
}

// Factory that creates a fresh store instance per test
function makeStore() {
  return createEntityStore<ITestEntity>('TestStore')
}

interface TestApiResponse {
  data: ITestEntity[]
  meta?: IPaginationMeta
  total?: number
}

type SearchMock = ReturnType<typeof vi.fn<(_params: Record<string, unknown>) => Promise<TestApiResponse>>>

function makeSearchMock(): SearchMock {
  return vi.fn<(_params: Record<string, unknown>) => Promise<TestApiResponse>>().mockResolvedValue({ data: [] })
}

function makeResource(overrides: { search?: SearchMock } = {}) {
  return {
    list: makeSearchMock(),
    search: overrides.search ?? makeSearchMock(),
  }
}

describe('useBaseList — characterization', () => {
  // Each test gets its own store to avoid cross-test state pollution
  let useStore: ReturnType<typeof makeStore>

  beforeEach(() => {
    useStore = makeStore()
  })

  afterEach(() => {
    // Reset store state between tests
    useStore.getState().reset()
    vi.clearAllMocks()
  })

  // ─────────────────────────────────────────────────────────────
  // 1. Default call: page/limit from store defaults, view undefined
  // ─────────────────────────────────────────────────────────────
  it('calls search with page/limit from store defaults and view undefined when no config overrides', async () => {
    const resource = makeResource()

    renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: true,
      })
    )

    await vi.waitFor(() => expect(resource.search).toHaveBeenCalled())

    const [params] = resource.search.mock.calls[0]
    expect(params.page).toBe(1)
    expect(params.limit).toBe(20)
    expect(params.view).toBeUndefined()
  })

  // ─────────────────────────────────────────────────────────────
  // 2. initialSearch with page/limit overrides store defaults
  // ─────────────────────────────────────────────────────────────
  it('uses page and limit from initialSearch when provided', async () => {
    const resource = makeResource()

    renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: true,
        initialSearch: { page: 3, limit: 5, filters: [], sort: [], includes: [], aggregates: [], user_id: null },
      })
    )

    await vi.waitFor(() => expect(resource.search).toHaveBeenCalled())

    const [params] = resource.search.mock.calls[0]
    expect(params.page).toBe(3)
    expect(params.limit).toBe(5)
  })

  // ─────────────────────────────────────────────────────────────
  // 3. overrideParams.filters are APPENDED after user filters
  // ─────────────────────────────────────────────────────────────
  it('concatenates user filters first, then override filters', async () => {
    const resource = makeResource()
    const userFilter: Filter = { field: 'status', operator: '=', value: 'active' }
    const overrideFilter: Filter = { field: 'tenant_id', operator: '=', value: 'abc' }

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
        overrideParams: { filters: [overrideFilter] },
        initialSearch: {
          filters: [userFilter],
          sort: [],
          includes: [],
          aggregates: [],
          user_id: null,
        },
      })
    )

    await act(async () => {
      await result.current.getList()
    })

    const [params] = resource.search.mock.calls[0]
    expect(params.filters).toEqual([userFilter, overrideFilter])
  })

  // ─────────────────────────────────────────────────────────────
  // 4. overrideParams.sort REPLACES user sort; without override uses user sort
  // ─────────────────────────────────────────────────────────────
  it('override sort replaces user sort', async () => {
    const resource = makeResource()
    const userSort: Sort = { field: 'name', direction: 'asc' }
    const overrideSort: Sort = { field: 'created_at', direction: 'desc' }

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
        overrideParams: { sort: [overrideSort] },
        initialSearch: {
          filters: [],
          sort: [userSort],
          includes: [],
          aggregates: [],
          user_id: null,
        },
      })
    )

    await act(async () => {
      await result.current.getList()
    })

    const [params] = resource.search.mock.calls[0]
    expect(params.sort).toEqual([overrideSort])
  })

  it('uses user sort when no overrideParams.sort present', async () => {
    const resource = makeResource()
    const userSort: Sort = { field: 'name', direction: 'asc' }

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
        overrideParams: { filters: [] }, // overrideParams present but no sort
        initialSearch: {
          filters: [],
          sort: [userSort],
          includes: [],
          aggregates: [],
          user_id: null,
        },
      })
    )

    await act(async () => {
      await result.current.getList()
    })

    const [params] = resource.search.mock.calls[0]
    expect(params.sort).toEqual([userSort])
  })

  // ─────────────────────────────────────────────────────────────
  // 5. includes: concat + dedup by relation
  // ─────────────────────────────────────────────────────────────
  it('deduplicates includes by relation (keeps first occurrence)', async () => {
    const resource = makeResource()
    const userInclude = { relation: 'unit' }
    const overrideInclude = { relation: 'unit' } // same relation — should dedup
    const extraOverrideInclude = { relation: 'tower' }

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
        overrideParams: { includes: [overrideInclude, extraOverrideInclude] },
        initialSearch: {
          filters: [],
          sort: [],
          includes: [userInclude],
          aggregates: [],
          user_id: null,
        },
      })
    )

    await act(async () => {
      await result.current.getList()
    })

    const [params] = resource.search.mock.calls[0]
    // After dedup, only one 'unit' and one 'tower'
    expect(params.includes).toEqual([{ relation: 'unit' }, { relation: 'tower' }])
    expect(params.includes).toHaveLength(2)
  })

  // ─────────────────────────────────────────────────────────────
  // 6. search.value with spaces → trimmed + case_sensitive: false
  // ─────────────────────────────────────────────────────────────
  it('trims search.value and forces case_sensitive: false', async () => {
    const resource = makeResource()

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
      })
    )

    await act(async () => {
      await result.current.getList(undefined, {
        search: { value: '  Juan  ', case_sensitive: true },
        filters: [],
        sort: [],
        includes: [],
        aggregates: [],
        user_id: null,
      })
    })

    const [params] = resource.search.mock.calls[0]
    expect(params.search).toEqual({ value: 'Juan', case_sensitive: false })
  })

  // ─────────────────────────────────────────────────────────────
  // 7. Response WITH meta → pagination built from meta
  // ─────────────────────────────────────────────────────────────
  it('builds pagination from response.meta when present', async () => {
    const meta = {
      current_page: 2,
      per_page: 10,
      total: 50,
      last_page: 5,
      from: 11,
      to: 20,
    }
    const searchMock = makeSearchMock().mockResolvedValue({ data: [{ id: '1' }], meta })
    const resource = makeResource({ search: searchMock })

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
      })
    )

    await act(async () => {
      await result.current.getList()
    })

    const storeState = useStore.getState()
    expect(storeState.pagination.page).toBe(2)
    expect(storeState.pagination.limit).toBe(10)
    expect(storeState.pagination.total).toBe(50)
    expect(storeState.pagination.pages).toBe(5)
  })

  // ─────────────────────────────────────────────────────────────
  // 8. Response WITHOUT meta and WITHOUT response.total (so totalItems falls back
  //    to data.length) — actual heuristic behavior:
  //    • when data.length < limit → pages = currentPage (no more pages)
  //    • when data.length === limit AND response.total is falsy AND totalItems is
  //      also falsy → pages = currentPage + 1 (the "might be more" heuristic)
  //    NOTE: the branch `data.length === pageSize → currentPage + 1` only fires
  //    when totalItems is falsy (0 / undefined). If data.length itself is used as
  //    totalItems (non-zero), Math.ceil(data.length / limit) = 1 when equal.
  // ─────────────────────────────────────────────────────────────
  it('sets pages = currentPage when no meta and data.length < limit (last page)', async () => {
    // 5 items returned, limit is 20 → clearly last page
    const fiveItems = Array.from({ length: 5 }, (_, i) => ({ id: String(i) }))
    const searchMock = makeSearchMock().mockResolvedValue({ data: fiveItems })
    const resource = makeResource({ search: searchMock })

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
      })
    )

    await act(async () => {
      await result.current.getList({ page: 1, limit: 20 })
    })

    const storeState = useStore.getState()
    // totalItems = data.length (5), Math.ceil(5/20) = 1 = currentPage
    expect(storeState.pagination.pages).toBe(1)
    expect(storeState.pagination.total).toBe(5)
  })

  it('uses Math.ceil(data.length / limit) for pages when no meta and response.total is 0', async () => {
    // NOTE (characterization): The branch `data.length === pageSize → page + 1`
    // is effectively dead code because `totalItems = response.total || data.length`
    // always falls back to data.length (a non-zero number when data exists).
    // ACTUAL behavior: pages = Math.ceil(totalItems / pageSize).
    // With 20 items and limit 20 → Math.ceil(20/20) = 1 (not 2).
    // This is the current production behavior. Plan 030 may or may not address it.
    const twentyItems = Array.from({ length: 20 }, (_, i) => ({ id: String(i) }))
    const searchMock = makeSearchMock().mockResolvedValue({ data: twentyItems, total: 0 })
    const resource = makeResource({ search: searchMock })

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
      })
    )

    await act(async () => {
      await result.current.getList({ page: 1, limit: 20 })
    })

    const storeState = useStore.getState()
    // totalItems = 0 || 20 = 20; Math.ceil(20/20) = 1 — the "heuristic" branch is dead code
    expect(storeState.pagination.pages).toBe(1)
  })

  // ─────────────────────────────────────────────────────────────
  // 9. Error → removeAllEntities + putMeta(null) + toast.error + re-throws
  // ─────────────────────────────────────────────────────────────
  it('on search error: clears entities, sets meta to null, shows toast, and re-throws', async () => {
    const { toast } = await import('@/shared/hooks/useToast')
    const searchError = new Error('Network error')

    // Pre-populate the store with some entities
    useStore.getState().addEntities([{ id: 'existing-1' }, { id: 'existing-2' }])

    const searchMock = makeSearchMock()
    searchMock.mockRejectedValue(searchError)
    const resource = makeResource({ search: searchMock })

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
      })
    )

    await expect(
      act(async () => {
        await result.current.getList()
      })
    ).rejects.toThrow('Network error')

    const storeState = useStore.getState()
    expect(storeState.entities).toHaveLength(0)
    expect(storeState.meta).toBeNull()
    expect(toast.error).toHaveBeenCalledWith('Error al obtener la lista')
  })

  // ─────────────────────────────────────────────────────────────
  // 10. onReset: preserves sort and includes, clears filters/search/aggregates
  // ─────────────────────────────────────────────────────────────
  it('onReset preserves sort and includes but clears filters, search, and aggregates', async () => {
    const resource = makeResource()
    const userSort = { field: 'name', direction: 'asc' as const }
    const userInclude = { relation: 'unit' }

    const { result } = renderHook(() =>
      useBaseList<ITestEntity>({
        store: useStore,
        resource,
        autoInit: false,
        initialSearch: {
          filters: [{ field: 'status', operator: '=' as const, value: 'active' }],
          sort: [userSort],
          includes: [userInclude],
          aggregates: [{ relation: 'charges', type: 'count' }],
          search: { value: 'test', case_sensitive: false },
          user_id: null,
        },
      })
    )

    // Call reset — it re-fetches with the reset state
    await act(async () => {
      await result.current.onReset()
    })

    // The second call (after reset) should have sort/includes but no filters/search/aggregates
    const lastCallParams = resource.search.mock.calls[resource.search.mock.calls.length - 1][0]
    expect(lastCallParams.sort).toEqual([userSort])
    expect(lastCallParams.includes).toEqual([userInclude])
    expect(lastCallParams.filters).toBeUndefined()
    expect(lastCallParams.search).toBeUndefined()
    expect(lastCallParams.aggregates).toBeUndefined()
  })
})
