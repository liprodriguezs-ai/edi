/**
 * Tests para PackageResource — custom methods.
 * Verifica que cada método custom construya la URL correcta y pase
 * el payload esperado al cliente HTTP. No realiza llamadas de red reales.
 */
import { PackageResource } from '@/modules/package/services/PackageResource'
import { apiClient } from '@/shared/api/apiClient'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ─── Mock del cliente HTTP ────────────────────────────────────────────────────

vi.mock('@/shared/api/apiClient', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/shared/api/apiClient')>()
  return {
    ...original,
    apiClient: {
      get: vi.fn(),
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    },
  }
})

const mockedGet = vi.mocked(apiClient.get)
const mockedPost = vi.mocked(apiClient.post)

beforeEach(() => {
  vi.clearAllMocks()
})

// ─── pickupPolicy ─────────────────────────────────────────────────────────────

describe('PackageResource.pickupPolicy', () => {
  it('llama a GET /package/pickup-policy', async () => {
    mockedGet.mockResolvedValueOnce({
      data: { requires_pickup_signature: false, requires_pickup_evidence: true },
    })

    const result = await PackageResource.pickupPolicy()

    expect(mockedGet).toHaveBeenCalledTimes(1)
    expect(mockedGet).toHaveBeenCalledWith('/package/pickup-policy')
    expect(result.requires_pickup_evidence).toBe(true)
  })
})

// ─── verifyCode ───────────────────────────────────────────────────────────────

describe('PackageResource.verifyCode', () => {
  it('llama a POST /package/verify-code con pickup_code', async () => {
    mockedPost.mockResolvedValueOnce({
      data: { unit: null, packages: [] },
    })

    await PackageResource.verifyCode('ABCD-1234')

    expect(mockedPost).toHaveBeenCalledWith('/package/verify-code', {
      pickup_code: 'ABCD-1234',
    })
  })
})

// ─── returnPackage ────────────────────────────────────────────────────────────

describe('PackageResource.returnPackage', () => {
  it('llama a POST /package/{id}/return con reason', async () => {
    const fakePackage = { id: 'pkg-1', unit_id: 'unit-1' }
    mockedPost.mockResolvedValueOnce({ data: { data: fakePackage } })

    const result = await PackageResource.returnPackage('pkg-1', 'No reclamado')

    expect(mockedPost).toHaveBeenCalledWith('/package/pkg-1/return', { reason: 'No reclamado' })
    expect(result.id).toBe('pkg-1')
  })

  it('llama a POST /package/{id}/return con payload vacío cuando no hay reason', async () => {
    mockedPost.mockResolvedValueOnce({ data: { data: { id: 'pkg-2', unit_id: 'unit-1' } } })

    await PackageResource.returnPackage('pkg-2')

    expect(mockedPost).toHaveBeenCalledWith('/package/pkg-2/return', {})
  })
})

// ─── incident ─────────────────────────────────────────────────────────────────

describe('PackageResource.incident', () => {
  it('llama a POST /package/{id}/incident con reason', async () => {
    mockedPost.mockResolvedValueOnce({ data: { data: { id: 'pkg-3', unit_id: 'unit-1' } } })

    await PackageResource.incident('pkg-3', 'Paquete dañado')

    expect(mockedPost).toHaveBeenCalledWith('/package/pkg-3/incident', {
      reason: 'Paquete dañado',
    })
  })
})

// ─── reassign ─────────────────────────────────────────────────────────────────

describe('PackageResource.reassign', () => {
  it('llama a POST /package/{id}/reassign con unit_id', async () => {
    mockedPost.mockResolvedValueOnce({ data: { data: { id: 'pkg-4', unit_id: 'unit-2' } } })

    await PackageResource.reassign('pkg-4', 'unit-2')

    expect(mockedPost).toHaveBeenCalledWith('/package/pkg-4/reassign', { unit_id: 'unit-2' })
  })
})

// ─── batch ────────────────────────────────────────────────────────────────────

describe('PackageResource.batch', () => {
  it('llama a POST /package/intake-batch con array de paquetes', async () => {
    const fakePackages = [{ id: 'p1', unit_id: 'u1' }, { id: 'p2', unit_id: 'u2' }]
    mockedPost.mockResolvedValueOnce({ data: { data: fakePackages } })

    const payload = { packages: [{ unit_id: 'u1' }, { unit_id: 'u2' }] }
    const result = await PackageResource.batch(payload)

    expect(mockedPost).toHaveBeenCalledWith('/package/intake-batch', payload)
    expect(result).toHaveLength(2)
  })
})

// ─── myPackages ───────────────────────────────────────────────────────────────

describe('PackageResource.myPackages', () => {
  it('llama a GET /my-packages sin params cuando se llama sin argumentos', async () => {
    mockedGet.mockResolvedValueOnce({ data: { data: [] } })

    await PackageResource.myPackages()

    expect(mockedGet).toHaveBeenCalledWith('/my-packages', { params: undefined })
  })

  it('llama a GET /my-packages con ?history=1 para el historial', async () => {
    mockedGet.mockResolvedValueOnce({ data: { data: [] } })

    await PackageResource.myPackages({ history: 1 })

    expect(mockedGet).toHaveBeenCalledWith('/my-packages', { params: { history: 1 } })
  })
})

// ─── aging ────────────────────────────────────────────────────────────────────

describe('PackageResource.aging', () => {
  it('llama a GET /package/report/aging', async () => {
    mockedGet.mockResolvedValueOnce({
      data: {
        generated_at: '2026-06-29T00:00:00Z',
        total_pending: 3,
        buckets: {
          d0_2: { count: 1, packages: [] },
          d3_7: { count: 1, packages: [] },
          d7_plus: { count: 1, packages: [] },
        },
      },
    })

    const result = await PackageResource.aging()

    expect(mockedGet).toHaveBeenCalledWith('/package/report/aging')
    expect(result.total_pending).toBe(3)
  })
})

// ─── agingExport ─────────────────────────────────────────────────────────────

describe('PackageResource.agingExport', () => {
  it('llama a GET /package/report/aging/export con responseType blob', async () => {
    const fakeBlob = new Blob(['xlsx-data'], { type: 'application/vnd.ms-excel' })
    mockedGet.mockResolvedValueOnce({ data: fakeBlob })

    const result = await PackageResource.agingExport()

    expect(mockedGet).toHaveBeenCalledWith('/package/report/aging/export', {
      responseType: 'blob',
    })
    expect(result).toBe(fakeBlob)
  })
})

// ─── pendingCount ─────────────────────────────────────────────────────────────

describe('PackageResource.pendingCount', () => {
  it('llama a GET /package/report/pending-count', async () => {
    mockedGet.mockResolvedValueOnce({ data: { total: 10, d7_plus: 2 } })

    const result = await PackageResource.pendingCount()

    expect(mockedGet).toHaveBeenCalledWith('/package/report/pending-count')
    expect(result.total).toBe(10)
    expect(result.d7_plus).toBe(2)
  })
})
