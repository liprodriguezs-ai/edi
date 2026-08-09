import { ChatMessageResource } from '@/modules/chat/services/ChatResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQueryClient } from '@tanstack/react-query'

/** Payload del mensaje a enviar al canal. */
export interface SendMessagePayload {
  /** HTML enriquecido producido por el editor TipTap. Requerido si body no está. */
  body_html?: string
  /** Texto plano (fallback). Requerido si body_html no está. */
  body?: string
  /** ID del mensaje al que se responde (reply). */
  reply_to_id?: string
  /** Imágenes adjuntas (ya comprimidas a WebP por el compositor). */
  attachments?: File[]
  /** Título opcional por imagen, en el mismo orden que attachments. */
  attachment_titles?: string[]
}

/**
 * Envío de mensaje al canal. Soporta:
 *  - Texto plano (body)
 *  - HTML enriquecido (body_html)
 *  - Adjuntos de imagen (attachments[] + attachment_titles[]) → FormData
 *
 * El acceso ya viene resuelto por el backend (can_write) y la caja solo se
 * muestra cuando corresponde, así que un 403 no ocurre en el flujo normal.
 */
export function useSendMessage(channelId: string | null, onSent: () => void) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: SendMessagePayload) => {
      // Con adjuntos → multipart FormData
      if (payload.attachments?.length) {
        const formData = new FormData()
        formData.append('chat_channel_id', channelId ?? '')
        if (payload.body_html) formData.append('body_html', payload.body_html)
        if (payload.body) formData.append('body', payload.body)
        if (payload.reply_to_id) formData.append('reply_to_id', payload.reply_to_id)
        payload.attachments.forEach((file, i) => {
          formData.append('attachments[]', file)
          formData.append('attachment_titles[]', payload.attachment_titles?.[i] ?? '')
        })
        return ChatMessageResource.create(formData)
      }

      // Sin adjuntos → JSON estándar
      return ChatMessageResource.create({
        chat_channel_id: channelId,
        body_html: payload.body_html,
        body: payload.body,
        reply_to_id: payload.reply_to_id,
      })
    },
    onSuccess: () => {
      onSent()
      void queryClient.invalidateQueries({ queryKey: ['chat', 'messages', channelId] })
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo enviar el mensaje')
    },
  })
}
