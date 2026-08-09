/**
 * Tests para ComplaintResource — custom methods.
 * Verifica que cada método custom construya la URL correcta y pase
 * el payload esperado al cliente HTTP. No realiza llamadas de red reales.
 */
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'
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

// ─── transition ───────────────────────────────────────────────────────────────

describe('ComplaintResource.transition', () => {
  it('llama a POST /complaint/{id}/transition con complaint_status_id', async () => {
    const fakeComplaint = { id: 'complaint-1', complaint_status_id: 'status-2' }
    mockedPost.mockResolvedValueOnce({ data: { data: fakeComplaint } })

    const result = await ComplaintResource.transition('complaint-1', 'status-2')

    expect(mockedPost).toHaveBeenCalledWith(
      '/complaint/complaint-1/transition',
      { complaint_status_id: 'status-2' },
      { skipPermissionsModal: true }
    )
    expect(result.complaint_status_id).toBe('status-2')
  })
})

// ─── assign ───────────────────────────────────────────────────────────────────

describe('ComplaintResource.assign', () => {
  it('llama a POST /complaint/{id}/assign con assigned_user_id', async () => {
    const fakeComplaint = { id: 'complaint-1', assigned_user_id: 'user-2' }
    mockedPost.mockResolvedValueOnce({ data: { data: fakeComplaint } })

    const result = await ComplaintResource.assign('complaint-1', 'user-2')

    expect(mockedPost).toHaveBeenCalledWith(
      '/complaint/complaint-1/assign',
      { assigned_user_id: 'user-2' },
      { skipPermissionsModal: true }
    )
    expect(result.assigned_user_id).toBe('user-2')
  })
})

// ─── postMessage ────────────────────────────────────────────────────────────

describe('ComplaintResource.postMessage', () => {
  it('llama a POST /complaint/{id}/message con el FormData recibido', async () => {
    const fakeMessage = { id: 'msg-1', complaint_id: 'complaint-1', body: 'Hola' }
    mockedPost.mockResolvedValueOnce({ data: { data: fakeMessage } })

    const form = new FormData()
    form.append('body', 'Hola')

    const result = await ComplaintResource.postMessage('complaint-1', form)

    expect(mockedPost).toHaveBeenCalledWith('/complaint/complaint-1/message', form, {
      skipPermissionsModal: true,
    })
    expect(result.id).toBe('msg-1')
  })
})

// ─── messages ─────────────────────────────────────────────────────────────────

describe('ComplaintResource.messages', () => {
  it('llama a GET /complaint/{id}/messages', async () => {
    const fakeMessages = [{ id: 'msg-1', complaint_id: 'complaint-1', body: 'Hola' }]
    mockedGet.mockResolvedValueOnce({ data: { data: fakeMessages } })

    const result = await ComplaintResource.messages('complaint-1')

    expect(mockedGet).toHaveBeenCalledWith('/complaint/complaint-1/messages', {
      skipPermissionsModal: true,
    })
    expect(result).toHaveLength(1)
  })
})

// ─── create ───────────────────────────────────────────────────────────────────

describe('ComplaintResource.create', () => {
  it('llama a POST /complaint con skipPermissionsModal (403 es regla de negocio)', async () => {
    const fakeComplaint = { id: 'complaint-1', case_number: 'D-2026-000001' }
    mockedPost.mockResolvedValueOnce({ data: { data: fakeComplaint } })

    const payload = {
      title: 'Ruido excesivo',
      complaint_category_id: 'cat-1',
      complaint_priority_id: 'pri-1',
      against_description: 'Vecino con música alta',
    }

    const result = await ComplaintResource.create(payload)

    expect(mockedPost).toHaveBeenCalledWith('/complaint', payload, { skipPermissionsModal: true })
    expect(result.id).toBe('complaint-1')
  })
})

// ─── eligibleAssignees ──────────────────────────────────────────────────────

describe('ComplaintResource.eligibleAssignees', () => {
  it('llama a GET /complaint/{id}/eligible-assignees', async () => {
    const fakeAssignees = [{ id: 'user-1', name: 'Presidente' }]
    mockedGet.mockResolvedValueOnce({ data: { data: fakeAssignees } })

    const result = await ComplaintResource.eligibleAssignees('complaint-1')

    expect(mockedGet).toHaveBeenCalledWith('/complaint/complaint-1/eligible-assignees')
    expect(result).toHaveLength(1)
  })
})
