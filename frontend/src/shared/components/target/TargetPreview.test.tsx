import { TargetPreview } from '@/shared/components/target/TargetPreview'
import { isSelectorReady } from '@/shared/utils/targetSelector'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

afterEach(cleanup)

function renderOne(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>)
}

// ── isSelectorReady (readiness gate — F3) ─────────────────────────────────────

describe('isSelectorReady', () => {
  it('types without sub-selection are always ready', () => {
    expect(isSelectorReady('all', null)).toBe(true)
    expect(isSelectorReady('with_vehicles', null)).toBe(true)
    expect(isSelectorReady('board', null)).toBe(true)
  })

  it('sub-selection types are NOT ready until their id list is non-empty', () => {
    expect(isSelectorReady('by_type', null)).toBe(false)
    expect(isSelectorReady('by_type', { unit_type_ids: [] })).toBe(false)
    expect(isSelectorReady('by_type', { unit_type_ids: ['ut-1'] })).toBe(true)
    expect(isSelectorReady('by_tower', { tower_ids: [] })).toBe(false)
    expect(isSelectorReady('by_tower', { tower_ids: ['t1'] })).toBe(true)
    expect(isSelectorReady('by_section', { section_ids: ['s1'] })).toBe(true)
    expect(isSelectorReady('by_feature', { unit_feature_ids: ['f1'] })).toBe(true)
    expect(isSelectorReady('manual', { unit_ids: [] })).toBe(false)
    expect(isSelectorReady('manual', { unit_ids: ['u1'] })).toBe(true)
  })

  it('single_unit ready only with a non-empty unit_id', () => {
    expect(isSelectorReady('single_unit', { unit_id: 'u-1' })).toBe(true)
    expect(isSelectorReady('single_unit', { unit_id: '' })).toBe(false)
    expect(isSelectorReady('single_unit', null)).toBe(false)
  })

  it('by_floor ready only with a non-empty floors array', () => {
    expect(isSelectorReady('by_floor', { floors: ['3'] })).toBe(true)
    expect(isSelectorReady('by_floor', { floors: [] })).toBe(false)
  })

  it('unknown type is never ready', () => {
    expect(isSelectorReady('nope', null)).toBe(false)
    expect(isSelectorReady('nope', { unit_ids: ['u1'] })).toBe(false)
  })
})

// ── TargetPreview render behaviour (F1 scope, F2 states, F3 gate, F4 a11y) ─────

describe('TargetPreview', () => {
  it('does NOT fetch while the selector is incomplete (readiness gate)', async () => {
    const fetcher = vi.fn().mockResolvedValue(3)
    renderOne(
      <TargetPreview
        previewScope='announcement'
        targetType='by_type'
        previewSelector={null}
        fetcher={fetcher}
        noun='residentes'
      />
    )
    expect(await screen.findByText(/Selecciona el alcance/i)).toBeInTheDocument()
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('fetches and shows the count once the selector is ready', async () => {
    const fetcher = vi.fn().mockResolvedValue(7)
    renderOne(
      <TargetPreview
        previewScope='announcement'
        targetType='all'
        previewSelector={null}
        fetcher={fetcher}
        noun='residentes'
      />
    )
    expect(await screen.findByText(/7 residentes/)).toBeInTheDocument()
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('renders a real 0 as "0 residentes" (not indistinguishable from loading)', async () => {
    const fetcher = vi.fn().mockResolvedValue(0)
    renderOne(
      <TargetPreview
        previewScope='announcement'
        targetType='all'
        previewSelector={null}
        fetcher={fetcher}
        noun='residentes'
      />
    )
    expect(await screen.findByText(/0 residentes/)).toBeInTheDocument()
  })

  it('shows a discreet error message when the fetcher rejects', async () => {
    const fetcher = vi.fn().mockRejectedValue(new Error('403'))
    renderOne(
      <TargetPreview
        previewScope='announcement'
        targetType='all'
        previewSelector={null}
        fetcher={fetcher}
        noun='residentes'
      />
    )
    expect(await screen.findByText(/No se pudo calcular el alcance/i)).toBeInTheDocument()
  })

  it('exposes the preview as a polite live region (role=status) for screen readers', () => {
    const fetcher = vi.fn().mockResolvedValue(1)
    renderOne(
      <TargetPreview
        previewScope='announcement'
        targetType='all'
        previewSelector={null}
        fetcher={fetcher}
        noun='residentes'
      />
    )
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite')
  })

  it('scopes the cache key so two endpoints with the SAME shape each fetch their own count', async () => {
    // Without previewScope in the queryKey both previews would share one cache entry and
    // only the first queryFn would run — the announcement preview would render the
    // charge-batch UNIT count as residents. With the scope, the keys differ → both fetch.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const unitsFetcher = vi.fn().mockResolvedValue(10)
    const peopleFetcher = vi.fn().mockResolvedValue(4)
    render(
      <QueryClientProvider client={client}>
        <TargetPreview
          previewScope='charge-batch'
          targetType='all'
          previewSelector={null}
          fetcher={unitsFetcher}
          noun='unidades'
        />
        <TargetPreview
          previewScope='announcement'
          targetType='all'
          previewSelector={null}
          fetcher={peopleFetcher}
          noun='residentes'
        />
      </QueryClientProvider>
    )
    await waitFor(() => {
      expect(unitsFetcher).toHaveBeenCalledTimes(1)
      expect(peopleFetcher).toHaveBeenCalledTimes(1)
    })
    expect(await screen.findByText(/10 unidades/)).toBeInTheDocument()
    expect(await screen.findByText(/4 residentes/)).toBeInTheDocument()
  })
})
