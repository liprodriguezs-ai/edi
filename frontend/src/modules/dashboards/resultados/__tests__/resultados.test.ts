/**
 * Tests para el tablero Estado de Resultados.
 * Cubre: schema types, ResultadosResource (URL y mapeo de payload).
 */
import type { IResultadosPayload } from '@/modules/dashboards/resultados/schema'
import { ResultadosResource } from '@/modules/dashboards/resultados/services/ResultadosResource'
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

const SAMPLE_PAYLOAD: IResultadosPayload = {
  series: [
    {
      label: 'Jun 2026',
      year: 2026,
      month: 6,
      income: money(150000),
      expense: money(80000),
      net: money(70000),
    },
    {
      label: 'May 2026',
      year: 2026,
      month: 5,
      income: money(120000),
      expense: money(60000),
      net: money(60000),
    },
  ],
  totals: {
    income: money(270000),
    expense: money(140000),
    net: money(130000),
    margin_pct: 48,
  },
}

describe('ResultadosResource', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetch calls GET /treasury/income-expense-series with default months=12', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ResultadosResource.fetch()

    expect(mockGet).toHaveBeenCalledOnce()
    const [url, config] = mockGet.mock.calls[0]
    expect(url).toBe('/treasury/income-expense-series')
    expect((config as { params: { months: number } }).params.months).toBe(12)
    expect(result).toEqual(SAMPLE_PAYLOAD)
  })

  it('fetch passes custom months param', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    await ResultadosResource.fetch(6)

    const [, config] = mockGet.mock.calls[0]
    expect((config as { params: { months: number } }).params.months).toBe(6)
  })

  it('payload contains Money objects (not raw minor-unit integers)', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ResultadosResource.fetch()

    expect(typeof result.totals.income.amount).toBe('string')
    expect(result.totals.income.currency).toBe('MXN')
    expect(moneyToMinorUnits(result.totals.income)).toBe(270000)
    expect(moneyToMinorUnits(result.totals.expense)).toBe(140000)
    expect(moneyToMinorUnits(result.totals.net)).toBe(130000)
    expect(moneyToMinorUnits(result.series[0].income)).toBe(150000)
    expect(moneyToMinorUnits(result.series[0].net)).toBe(70000)
  })

  it('net equals income minus expense per bucket', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ResultadosResource.fetch()

    for (const bucket of result.series) {
      expect(moneyToMinorUnits(bucket.net)).toBe(
        moneyToMinorUnits(bucket.income) - moneyToMinorUnits(bucket.expense)
      )
    }
  })

  it('totals net equals sum of series net', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ResultadosResource.fetch()
    const sumNet = result.series.reduce((acc, b) => acc + moneyToMinorUnits(b.net), 0)

    expect(moneyToMinorUnits(result.totals.net)).toBe(sumNet)
  })

  it('propagates API errors', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    await expect(ResultadosResource.fetch()).rejects.toThrow('Network error')
  })
})
