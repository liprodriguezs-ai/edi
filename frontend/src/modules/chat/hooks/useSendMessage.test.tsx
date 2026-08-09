/**
 * Characterization tests for useSendMessage.
 *
 * Strategy: QueryClientProvider wrapper defined inline; mock the resource
 * module so no real HTTP calls are made. Assert on onSent callback and query
 * invalidation (by spying on queryClient.invalidateQueries).
 *
 * The hook now accepts a SendMessagePayload (body_html / body / attachments /
 * attachment_titles / reply_to_id) and builds FormData when attachments are
 * present.
 */
import { useSendMessage, type SendMessagePayload } from '@/modules/chat/hooks/useSendMessage'
import { ChatMessageResource } from '@/modules/chat/services/ChatResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mocks ──────────────────────────────────────────────────────────────────

vi.mock('@/shared/hooks/useToast', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
}))

vi.mock('@/modules/chat/services/ChatResource', () => ({
  ChatMessageResource: {
    create: vi.fn(),
  },
}))

// ── Helpers ────────────────────────────────────────────────────────────────

const mockCreate = vi.mocked(ChatMessageResource.create)

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

const CHANNEL_ID = 'channel-uuid-001'

describe('useSendMessage — characterization', () => {
  let queryClient: QueryClient
  let invalidateSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
    vi.clearAllMocks()
  })

  // ── JSON send (sin adjuntos, texto plano) ────────────────────────────────
  it('envía body_html como JSON cuando no hay adjuntos', async () => {
    mockCreate.mockResolvedValue({ id: 'msg-1', body: 'Hola', body_html: '<p>Hola</p>' } as never)

    const onSent = vi.fn()
    const { result } = renderHook(
      () => useSendMessage(CHANNEL_ID, onSent),
      { wrapper: makeWrapper(queryClient) },
    )

    const payload: SendMessagePayload = { body_html: '<p>Hola a todos</p>' }

    await act(async () => {
      result.current.mutate(payload)
      await vi.waitFor(() => expect(onSent).toHaveBeenCalled())
    })

    expect(onSent).toHaveBeenCalledTimes(1)
    expect(invalidateSpy).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['chat', 'messages', CHANNEL_ID] }),
    )
    expect(mockCreate).toHaveBeenCalledWith(
      expect.objectContaining({
        chat_channel_id: CHANNEL_ID,
        body_html: '<p>Hola a todos</p>',
      }),
    )
  })

  // ── JSON send (body plano, compatibilidad) ──────────────────────────────
  it('envía body plano como JSON cuando no hay adjuntos', async () => {
    mockCreate.mockResolvedValue({ id: 'msg-2', body: 'Hola' } as never)

    const onSent = vi.fn()
    const { result } = renderHook(
      () => useSendMessage(CHANNEL_ID, onSent),
      { wrapper: makeWrapper(queryClient) },
    )

    const payload: SendMessagePayload = { body: 'Hola todos' }

    await act(async () => {
      result.current.mutate(payload)
      await vi.waitFor(() => expect(onSent).toHaveBeenCalled())
    })

    expect(mockCreate).toHaveBeenCalledWith(
      expect.objectContaining({ chat_channel_id: CHANNEL_ID, body: 'Hola todos' }),
    )
  })

  // ── FormData send (con adjuntos) ─────────────────────────────────────────
  it('envía FormData cuando hay adjuntos de imagen', async () => {
    mockCreate.mockResolvedValue({ id: 'msg-3', body_html: '<p>Foto</p>', media: [] } as never)

    const onSent = vi.fn()
    const { result } = renderHook(
      () => useSendMessage(CHANNEL_ID, onSent),
      { wrapper: makeWrapper(queryClient) },
    )

    const fakeFile = new File(['data'], 'foto.webp', { type: 'image/webp' })
    const payload: SendMessagePayload = {
      body_html: '<p>Foto</p>',
      attachments: [fakeFile],
      attachment_titles: ['Mi foto'],
    }

    await act(async () => {
      result.current.mutate(payload)
      await vi.waitFor(() => expect(onSent).toHaveBeenCalled())
    })

    // Debe recibir una instancia de FormData
    const callArg = mockCreate.mock.calls[0][0]
    expect(callArg).toBeInstanceOf(FormData)

    const fd = callArg as FormData
    expect(fd.get('chat_channel_id')).toBe(CHANNEL_ID)
    expect(fd.get('body_html')).toBe('<p>Foto</p>')
    expect(fd.get('attachments[]')).toBe(fakeFile)
    expect(fd.get('attachment_titles[]')).toBe('Mi foto')
  })

  // ── Error: no llama onSent ───────────────────────────────────────────────
  it('no llama onSent cuando la mutación falla', async () => {
    mockCreate.mockRejectedValue(new Error('Network error'))

    const onSent = vi.fn()
    const { result } = renderHook(
      () => useSendMessage(CHANNEL_ID, onSent),
      { wrapper: makeWrapper(queryClient) },
    )

    await act(async () => {
      result.current.mutate({ body: 'Hola fallido' })
      await vi.waitFor(() => expect(result.current.isError).toBe(true))
    })

    expect(onSent).not.toHaveBeenCalled()
    expect(mockCreate).toHaveBeenCalledTimes(1)
  })
})
