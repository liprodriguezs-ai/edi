/**
 * Tests para el tablero de Cobranza.
 * Cubre: schema types, CobranzaResource (URL y mapeo de payload), validaciones de negocio.
 */
import type { ICobranzaPayload } from '@/modules/dashboards/cobranza/schema'
import { CobranzaResource } from '@/modules/dashboards/cobranza/services/CobranzaResource'
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

const SAMPLE_PAYLOAD: ICobranzaPayload = {
  totals: {
    total_outstanding: money(450000),
    units_with_debt: 5,
    avg_days_overdue: 42,
    collection_rate_pct: 78,
  },
  buckets: {
    current: money(0),
    d1_30: money(120000),
    d31_60: money(180000),
    d61_90: money(100000),
    d90_plus: money(50000),
  },
  by_status: [
    { status_key: 'overdue', label: 'Vencido', count: 4, amount: money(350000) },
    { status_key: 'partial', label: 'Pago parcial', count: 1, amount: money(100000) },
  ],
  top_debtors: [
    { unit_label: 'A-101', outstanding: money(200000), charge_count: 3 },
    { unit_label: 'B-205', outstanding: money(150000), charge_count: 2 },
  ],
}

describe('CobranzaResource', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetch calls GET /collections/aging', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await CobranzaResource.fetch()

    expect(mockGet).toHaveBeenCalledOnce()
    const [url] = mockGet.mock.calls[0]
    expect(url).toBe('/collections/aging')
    expect(result).toEqual(SAMPLE_PAYLOAD)
  })

  it('payload amounts are Money objects (amount as string, in minor units)', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await CobranzaResource.fetch()

    expect(Number.isInteger(moneyToMinorUnits(result.totals.total_outstanding))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.buckets.d1_30))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.by_status[0].amount))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.top_debtors[0].outstanding))).toBe(true)
  })

  it('top_debtors are capped to 10 entries by convention', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await CobranzaResource.fetch()

    // El backend ya garantiza ≤10; aquí verificamos que el tipo lo permite
    expect(result.top_debtors.length).toBeLessThanOrEqual(10)
  })

  it('collection_rate_pct is integer between 0 and 100', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await CobranzaResource.fetch()
    const rate = result.totals.collection_rate_pct

    expect(Number.isInteger(rate)).toBe(true)
    expect(rate).toBeGreaterThanOrEqual(0)
    expect(rate).toBeLessThanOrEqual(100)
  })

  it('bucket amounts sum to total_outstanding (approximately)', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await CobranzaResource.fetch()
    const { buckets, totals } = result
    const bucketSum =
      moneyToMinorUnits(buckets.current) +
      moneyToMinorUnits(buckets.d1_30) +
      moneyToMinorUnits(buckets.d31_60) +
      moneyToMinorUnits(buckets.d61_90) +
      moneyToMinorUnits(buckets.d90_plus)

    // Puede haber diferencia por redondeos, pero deben ser iguales en este fixture
    expect(bucketSum).toBe(moneyToMinorUnits(totals.total_outstanding))
  })

  it('by_status entries have string status_key and Money amount', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await CobranzaResource.fetch()

    for (const s of result.by_status) {
      expect(typeof s.status_key).toBe('string')
      expect(typeof s.amount.amount).toBe('string')
      expect(typeof s.label).toBe('string')
    }
  })

  it('propagates API errors', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    await expect(CobranzaResource.fetch()).rejects.toThrow('Network error')
  })
})
