import type { IChatChannel, IChatMessage, IChatMessageReport } from '@/modules/chat/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import { queryOptions } from '@tanstack/react-query'

export const ChatChannelResource = createCrudApi<
  IChatChannel,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    direct: (_userId: string) => Promise<IChatChannel>
  }
>({
  basePath: '/chat_channel',
  customMethods: (basePath) => ({
    // Abre (o recupera) el canal directo 1:1 con otro usuario del condominio (§13.4).
    direct: async (userId) => {
      const { data } = await apiClient.post<{ data: IChatChannel }>(`${basePath}/direct`, {
        user_id: userId,
      })
      return data.data
    },
    // Nota: "copiar config" se resuelve en el cliente (buildChannelFormDefaults +
    // setValue en ChannelForm). El endpoint POST /chat-channel/{id}/copy-config
    // existe y está testeado para uso por API, pero el front no lo consume.
  }),
})

/**
 * Query compartida de la lista COMPLETA de canales del condominio (incluye `members` +
 * `members.user`, necesarios para pintar los nombres de los chats directos en el sidebar).
 * La usa `ChatPage`. Extraída acá para que la key y la forma NO diverjan si otra pantalla
 * necesita la misma lista. Para el selector "Copiar config de…" NO uses esta query pesada:
 * ahí basta la variante ligera sin includes (ver `ChannelEditorPage`).
 */
export const chatChannelsQueryOptions = queryOptions({
  queryKey: ['chat', 'channels'],
  queryFn: async () => {
    const { data } = await ChatChannelResource.search({
      limit: 100,
      filters: [{ field: 'is_archived', operator: '=', value: false }],
      includes: [{ relation: 'members' }, { relation: 'members.user' }],
      sort: [{ field: 'created_at', direction: 'asc' }],
    })
    return data
  },
})

export const ChatMessageResource = createCrudApi<
  IChatMessage,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    hide: (_id: string) => Promise<unknown>
    unhide: (_id: string) => Promise<unknown>
    report: (_id: string, _reason: string) => Promise<unknown>
    edit: (_id: string, _body: string) => Promise<unknown>
    react: (_id: string, _emoji?: string) => Promise<unknown>
    unreact: (_id: string, _emoji?: string) => Promise<unknown>
  }
>({
  basePath: '/chat_message',
  customMethods: () => ({
    hide: async (id) => {
      const { data } = await apiClient.post(`/chat-message/${id}/hide`)
      return data
    },
    unhide: async (id) => {
      const { data } = await apiClient.post(`/chat-message/${id}/unhide`)
      return data
    },
    report: async (id, reason) => {
      const { data } = await apiClient.post(`/chat-message/${id}/report`, { reason })
      return data
    },
    edit: async (id, body) => {
      const { data } = await apiClient.post(`/chat-message/${id}/edit`, { body })
      return data
    },
    react: async (id, emoji = '👍') => {
      const { data } = await apiClient.post(`/chat-message/${id}/react`, { emoji })
      return data
    },
    unreact: async (id, emoji = '👍') => {
      const { data } = await apiClient.delete(`/chat-message/${id}/react`, { data: { emoji } })
      return data
    },
  }),
})

export const ChatMessageReportResource = createCrudApi<
  IChatMessageReport,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    dismiss: (_id: string) => Promise<unknown>
  }
>({
  basePath: '/chat_message_report',
  customMethods: () => ({
    dismiss: async (id) => {
      const { data } = await apiClient.post(`/chat-message-report/${id}/dismiss`)
      return data
    },
  }),
})
