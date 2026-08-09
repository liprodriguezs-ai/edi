/**
 * Unit tests for ImportResource.
 *
 * Mocks apiClient to assert the correct HTTP calls are made without
 * hitting a real server.
 */
import { ImportResource } from '@/modules/import/services/ImportResource'
import { ApiError, apiClient } from '@/shared/api/apiClient'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Keep the real ApiError class (used by validateImport to detect a validation
// 422) while mocking only the axios instance.
vi.mock('@/shared/api/apiClient', async (importActual) => {
  const actual = await importActual<typeof import('@/shared/api/apiClient')>()
  return {
    ...actual,
    apiClient: {
      get: vi.fn(),
      post: vi.fn(),
    },
  }
})

// saveBlob is a side-effect helper in ReportsResource; mock it away
vi.mock('@/modules/reports/services/ReportsResource', () => ({
  saveBlob: vi.fn(),
}))

const mockGet = vi.mocked(apiClient.get)
const mockPost = vi.mocked(apiClient.post)

describe('ImportResource', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── validateImport ─────────────────────────────────────────────────────────

  it('validateImport sends a POST with FormData containing the file', async () => {
    const mockResponse = {
      total: 5,
      units_to_create: 3,
      residents_to_create: 3,
      payments_to_register: 5,
      blocked: 0,
      rows: [],
    }
    mockPost.mockResolvedValue({ data: mockResponse })

    const file = new File(['test'], 'pagos.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })

    const result = await ImportResource.validateImport(file)

    expect(mockPost).toHaveBeenCalledOnce()
    const [url, body] = mockPost.mock.calls[0]
    expect(url).toBe('/import/validate')
    expect(body).toBeInstanceOf(FormData)
    expect((body as FormData).get('file')).toBe(file)
    expect(result).toEqual(mockResponse)
  })

  it('validateImport propagates API errors', async () => {
    mockPost.mockRejectedValue(new Error('Network error'))

    const file = new File([''], 'pagos.xlsx')

    await expect(ImportResource.validateImport(file)).rejects.toThrow('Network error')
  })

  it('validateImport returns the validation body on a 422 with blocked rows', async () => {
    const body = {
      total: 2,
      units_to_create: 1,
      residents_to_create: 1,
      payments_to_register: 1,
      blocked: 1,
      rows: [{ row: 2, severity: 'error', column: 'monto', message: 'El monto es obligatorio.' }],
    }
    mockPost.mockRejectedValue(new ApiError('errores', 422, body))

    const result = await ImportResource.validateImport(new File([''], 'pagos.xlsx'))

    expect(result).toEqual(body)
  })

  it('validateImport rethrows hard failures (422 without a validation body)', async () => {
    mockPost.mockRejectedValue(
      new ApiError('Sin hoja PAGOS', 422, { message: 'El archivo no contiene la hoja "PAGOS"…' }),
    )

    await expect(ImportResource.validateImport(new File([''], 'pagos.xlsx'))).rejects.toThrow(
      'Sin hoja PAGOS',
    )
  })

  // ── executeImport ──────────────────────────────────────────────────────────

  it('executeImport sends a POST with FormData containing the file', async () => {
    mockPost.mockResolvedValue({ data: { import_run_id: 'run-uuid-1' } })

    const file = new File(['test'], 'pagos.xlsx')

    const result = await ImportResource.executeImport(file)

    expect(mockPost).toHaveBeenCalledOnce()
    const [url, body] = mockPost.mock.calls[0]
    expect(url).toBe('/import/execute')
    expect(body).toBeInstanceOf(FormData)
    expect((body as FormData).get('file')).toBe(file)
    expect(result).toEqual({ import_run_id: 'run-uuid-1' })
  })

  // ── getStatus ──────────────────────────────────────────────────────────────

  it('getStatus calls GET /import/{id}/status', async () => {
    const mockStatus = {
      id: 'run-uuid-1',
      status: 'ready' as const,
      file_path: null,
      progress: { processed: 100, total: 100 },
      report: null,
      error: null,
    }
    mockGet.mockResolvedValue({ data: mockStatus })

    const result = await ImportResource.getStatus('run-uuid-1')

    expect(mockGet).toHaveBeenCalledWith('/import/run-uuid-1/status')
    expect(result).toEqual(mockStatus)
  })

  // ── downloadTemplate ───────────────────────────────────────────────────────

  it('downloadTemplate calls GET /import/template with responseType blob', async () => {
    const blob = new Blob(['xlsx data'], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
    mockGet.mockResolvedValue({ data: blob })

    await ImportResource.downloadTemplate()

    expect(mockGet).toHaveBeenCalledWith('/import/template', { responseType: 'blob' })
  })
})
