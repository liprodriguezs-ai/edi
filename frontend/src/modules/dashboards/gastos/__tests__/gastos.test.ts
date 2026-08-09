/**
 * Tests para el tablero de Gastos.
 * Cubre: schema types, GastosResource (URL y mapeo de payload), invariantes de negocio.
 */
import type { IGastosPayload } from '@/modules/dashboards/gastos/schema'
import { GastosResource } from '@/modules/dashboards/gastos/services/GastosResource'
import { apiClient } from '@/shared/api/apiClient'
import { moneyToMinorUnits, type IMoney } from '@/shared/utils/money'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/api/apiClient', async (importActual) => {
  const actual = await importActual<typeof import('@/shared/api/apiClient')>()
  return { ...actual, apiClient: { get: vi.fn(), post: vi.fn() } }
})

const mockGet = vi.mocked(apiClient.get)

/** Fixture Money: amount siempre STRING, como lo serializa moneyphp. */
function money(minorUnits: number): IMoney {
  return {
    amount: String(minorUnits),
    currency: 'MXN',
    formatted: `$${(minorUnits / 100).toFixed(2)}`,
  }
}

const SAMPLE_PAYLOAD: IGastosPayload = {
  totals: {
    total: money(350000),
    recurring: money(210000),
    one_off: money(140000),
    recurring_pct: 60,
    provider_count: 4,
  },
  by_category: [
    { expense_category_id: 'cat-1', label: 'Mantenimiento', amount: money(180000), pct: 51 },
    { expense_category_id: 'cat-2', label: 'Servicios', amount: money(120000), pct: 34 },
    { expense_category_id: 'cat-3', label: 'Otros', amount: money(50000), pct: 15 },
  ],
  by_provider: [
    { provider_id: 'prov-1', label: 'Proveedor A', amount: money(150000) },
    { provider_id: 'prov-2', label: 'Proveedor B', amount: money(60000) },
  ],
  monthly: [
    { label: 'Abr 2026', year: 2026, month: 4, amount: money(90000) },
    { label: 'May 2026', year: 2026, month: 5, amount: money(120000) },
    { label: 'Jun 2026', year: 2026, month: 6, amount: money(140000) },
  ],
}

describe('GastosResource', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetch calls GET /treasury/expenses-breakdown with default months=12', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await GastosResource.fetch()

    expect(mockGet).toHaveBeenCalledOnce()
    const [url, config] = mockGet.mock.calls[0]
    expect(url).toBe('/treasury/expenses-breakdown')
    expect((config as { params: { months: number } }).params.months).toBe(12)
    expect(result).toEqual(SAMPLE_PAYLOAD)
  })

  it('fetch passes custom months param', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    await GastosResource.fetch(6)

    const [, config] = mockGet.mock.calls[0]
    expect((config as { params: { months: number } }).params.months).toBe(6)
  })

  it('all monetary fields are Money objects (amount as string, in minor units)', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await GastosResource.fetch()

    expect(typeof result.totals.total.amount).toBe('string')
    expect(Number.isInteger(moneyToMinorUnits(result.totals.total))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.totals.recurring))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.totals.one_off))).toBe(true)
    for (const cat of result.by_category) {
      expect(Number.isInteger(moneyToMinorUnits(cat.amount))).toBe(true)
    }
    for (const prov of result.by_provider) {
      expect(Number.isInteger(moneyToMinorUnits(prov.amount))).toBe(true)
    }
    for (const bucket of result.monthly) {
      expect(Number.isInteger(moneyToMinorUnits(bucket.amount))).toBe(true)
    }
  })

  it('recurring + one_off equals total', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await GastosResource.fetch()
    const { totals } = result

    expect(moneyToMinorUnits(totals.recurring) + moneyToMinorUnits(totals.one_off)).toBe(
      moneyToMinorUnits(totals.total)
    )
  })

  it('recurring_pct is integer between 0 and 100', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await GastosResource.fetch()
    const pct = result.totals.recurring_pct

    expect(Number.isInteger(pct)).toBe(true)
    expect(pct).toBeGreaterThanOrEqual(0)
    expect(pct).toBeLessThanOrEqual(100)
  })

  it('by_category pct values are integers between 0 and 100', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await GastosResource.fetch()

    for (const cat of result.by_category) {
      expect(Number.isInteger(cat.pct)).toBe(true)
      expect(cat.pct).toBeGreaterThanOrEqual(0)
      expect(cat.pct).toBeLessThanOrEqual(100)
    }
  })

  it('propagates API errors', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    await expect(GastosResource.fetch()).rejects.toThrow('Network error')
  })
})
