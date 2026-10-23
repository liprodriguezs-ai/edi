import { collectPages } from '@/modules/appointment/hooks/useAgenda'
import { describe, expect, it, vi } from 'vitest'

describe('collectPages', () => {
  it('keeps asking until the last page, so a busy month is not cut at 100', async () => {
    const fetchPage = vi.fn(async (page: number) => ({
      data: [`p${page}-a`, `p${page}-b`],
      meta: { current_page: page, last_page: 3, per_page: 2, total: 6, from: 1, to: 2 },
    }))

    await expect(collectPages(fetchPage)).resolves.toEqual(['p1-a', 'p1-b', 'p2-a', 'p2-b', 'p3-a', 'p3-b'])
    expect(fetchPage).toHaveBeenCalledTimes(3)
  })

  it('stops after one call when the response has no pagination data', async () => {
    const fetchPage = vi.fn(async () => ({ data: ['only'] }))

    await expect(collectPages(fetchPage)).resolves.toEqual(['only'])
    expect(fetchPage).toHaveBeenCalledTimes(1)
  })
})
