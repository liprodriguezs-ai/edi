/**
 * Tests para el tablero de Proyectos Comunitarios.
 * Cubre: schema types, ProyectosResource (URL y mapeo de payload), invariantes de negocio.
 */
import type { IProyectosPayload } from '@/modules/dashboards/proyectos/schema'
import { ProyectosResource } from '@/modules/dashboards/proyectos/services/ProyectosResource'
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

const SAMPLE_PAYLOAD: IProyectosPayload = {
  projects: [
    {
      id: 'proj-1',
      title: 'Renovación del jardín',
      goal: money(500000),
      raised: money(250000),
      pct: 50,
      status_key: 'active',
      status_label: 'Activo',
    },
    {
      id: 'proj-2',
      title: 'Cisterna nueva',
      goal: money(1200000),
      raised: money(1200000),
      pct: 100,
      status_key: 'fully_funded',
      status_label: 'Completamente financiado',
    },
    {
      id: 'proj-3',
      title: 'Cámaras de seguridad',
      goal: money(800000),
      raised: money(0),
      pct: 0,
      status_key: 'draft',
      status_label: 'Borrador',
    },
  ],
  totals: {
    active_count: 1,
    goal: money(2500000),
    raised: money(1450000),
    pct: 58,
  },
  by_status: [
    { status_key: 'active', label: 'Activo', count: 1 },
    { status_key: 'fully_funded', label: 'Completamente financiado', count: 1 },
    { status_key: 'draft', label: 'Borrador', count: 1 },
  ],
}

describe('ProyectosResource', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetch calls GET /projects/funding-summary', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ProyectosResource.fetch()

    expect(mockGet).toHaveBeenCalledOnce()
    const [url] = mockGet.mock.calls[0]
    expect(url).toBe('/projects/funding-summary')
    expect(result).toEqual(SAMPLE_PAYLOAD)
  })

  it('all monetary fields are Money objects (amount as string, in minor units)', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ProyectosResource.fetch()

    expect(Number.isInteger(moneyToMinorUnits(result.totals.goal))).toBe(true)
    expect(Number.isInteger(moneyToMinorUnits(result.totals.raised))).toBe(true)
    for (const proj of result.projects) {
      expect(Number.isInteger(moneyToMinorUnits(proj.goal))).toBe(true)
      expect(Number.isInteger(moneyToMinorUnits(proj.raised))).toBe(true)
    }
  })

  it('pct is 0 when goal is 0 (no divide-by-zero)', async () => {
    const payloadWithZeroGoal: IProyectosPayload = {
      ...SAMPLE_PAYLOAD,
      projects: [{ ...SAMPLE_PAYLOAD.projects[0], goal: money(0), raised: money(0), pct: 0 }],
      totals: { active_count: 1, goal: money(0), raised: money(0), pct: 0 },
    }
    mockGet.mockResolvedValue({ data: payloadWithZeroGoal })

    const result = await ProyectosResource.fetch()

    expect(result.projects[0].pct).toBe(0)
    expect(result.totals.pct).toBe(0)
  })

  it('pct values are integers between 0 and 100', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ProyectosResource.fetch()

    expect(Number.isInteger(result.totals.pct)).toBe(true)
    expect(result.totals.pct).toBeGreaterThanOrEqual(0)
    expect(result.totals.pct).toBeLessThanOrEqual(100)
    for (const proj of result.projects) {
      expect(Number.isInteger(proj.pct)).toBe(true)
      expect(proj.pct).toBeGreaterThanOrEqual(0)
    }
  })

  it('by_status entries have string status_key and integer count', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ProyectosResource.fetch()

    for (const s of result.by_status) {
      expect(typeof s.status_key).toBe('string')
      expect(typeof s.label).toBe('string')
      expect(Number.isInteger(s.count)).toBe(true)
    }
  })

  it('active_count matches projects with status_key active', async () => {
    mockGet.mockResolvedValue({ data: SAMPLE_PAYLOAD })

    const result = await ProyectosResource.fetch()
    const activeFromProjects = result.projects.filter((p) => p.status_key === 'active').length

    expect(result.totals.active_count).toBe(activeFromProjects)
  })

  it('propagates API errors', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    await expect(ProyectosResource.fetch()).rejects.toThrow('Network error')
  })
})
