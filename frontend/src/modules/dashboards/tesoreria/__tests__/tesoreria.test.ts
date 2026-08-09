/**
 * Tests para el tablero de Tesorería.
 * Cubre: schema types, TesoreriaResource (URL y mapeo de payload), invariantes de series.
 */
import type { ITesoreriaPayload } from '@/modules/dashboards/tesoreria/schema'
import { TesoreriaResource } from '@/modules/dashboards/tesoreria/services/TesoreriaResource'
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

const SAMPLE_PAYLOAD: ITesoreriaPayload = {
  accounts: [
    { id: 'acc-1', name: 'Cuenta BBVA', current_balance: money(500000) },
    { id: 'acc-2', name: 'Caja chica', current_balance: money(30000) },
  ],
  series: [
    {
      label: 'Abr 2026',
      year: 2026,
      month: 4,
      inflow: money(100000),
      outflow: money(60000),
      running_balance: money(40000),
    },
    {
      label: 'May 2026',
      year: 2026,
      month: 5,
      inflow: money(120000),
      outflow: money(50000),
      running_balance: money(110000),
    },
    {
      label: 'Jun 2026',
      year: 2026,
      month: 6,
      inflow: money(150000),
      outflow: money(80000),
      running_balance: money(180000),
    },
  ],
  totals: {
    total_balance: money(530000),
    account_count: 2,
    current_month_inflow: money(150000),
    current_month_outflow: money(80000),
  },
}

describe('TesoreriaResource', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetch calls GET /treasury/cashflow-series with default months=12', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await TesoreriaResource.fetch()

    expect(mockGet).toHaveBeenCalledOnce()
    const [url, config] = mockGet.mock.calls[0]
    expect(url).toBe('/treasury/cashflow-series')
    expect((config as { params: { months: number } }).params.months).toBe(12)
    expect(result).toEqual(SAMPLE_PAYLOAD)
  })

  it('fetch passes custom months param', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    await TesoreriaResource.fetch(6)

    const [, config] = mockGet.mock.calls[0]
    expect((config as { params: { months: number } }).params.months).toBe(6)
  })

  it('all monetary fields are Money objects (amount as string, in minor units)', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await TesoreriaResource.fetch()

    expect(typeof result.totals.total_balance.amount).toBe('string')
    expect(Number.isInteger(moneyToMinorUnits(result.totals.total_balance))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.totals.current_month_inflow))).toBe(true)
    for (const acc of result.accounts) {
      expect(Number.isInteger(moneyToMinorUnits(acc.current_balance))).toBe(true)
    }
    for (const bucket of result.series) {
      expect(Number.isInteger(moneyToMinorUnits(bucket.inflow))).toBe(true)
      expect(Number.isInteger(moneyToMinorUnits(bucket.outflow))).toBe(true)
      expect(Number.isInteger(moneyToMinorUnits(bucket.running_balance))).toBe(true)
    }
  })

  it('running_balance is cumulative: each entry equals sum of (inflow-outflow) up to that point', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await TesoreriaResource.fetch()

    let cumulative = 0
    for (const bucket of result.series) {
      cumulative += moneyToMinorUnits(bucket.inflow) - moneyToMinorUnits(bucket.outflow)
      expect(moneyToMinorUnits(bucket.running_balance)).toBe(cumulative)
    }
  })

  it('account_count matches accounts array length', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await TesoreriaResource.fetch()

    expect(result.totals.account_count).toBe(result.accounts.length)
  })

  it('total_balance matches sum of account balances', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await TesoreriaResource.fetch()
    const sumBalances = result.accounts.reduce(
      (acc, a) => acc + moneyToMinorUnits(a.current_balance),
      0
    )

    expect(moneyToMinorUnits(result.totals.total_balance)).toBe(sumBalances)
  })

  it('propagates API errors', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    await expect(TesoreriaResource.fetch()).rejects.toThrow('Network error')
  })
})
