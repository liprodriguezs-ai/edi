import { useCallback, useEffect, useRef, useState } from 'react'
import type { ITicketMessage } from '@/modules/ticket/schema'
import { TicketMessageResource } from '@/modules/ticket/services/TicketMessageResource'
import { sendTicketMessage } from '@/modules/ticket/services/TicketResource'
import { useTicketDetailStore } from '@/modules/ticket/store/ticketDetailStore'
import { useInitOnce } from '@/shared/hooks/useInitOnce'
import { toast } from '@/shared/hooks/useToast'

export function useTicketChat(ticketId: string | null) {
  const [messages, setMessages] = useState<ITicketMessage[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [isSending, setIsSending] = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)
  const bottomRef = useRef<HTMLDivElement>(null)
  const hasLoaded = useRef(false)

  const fetchMessages = useCallback(async () => {
    if (!ticketId) return

    if (!hasLoaded.current) {
      setIsLoading(true)
    }
    try {
      const response = await TicketMessageResource.search({
        filters: [{ field: 'ticket_id', operator: '=', value: ticketId }],
        sort: [{ field: 'created_at', direction: 'asc' }],
        includes: [{ relation: 'user' }, { relation: 'media' }],
        limit: 100,
      })
      setMessages(response.data ?? [])
      hasLoaded.current = true
    } catch {
      toast.error('Error al cargar mensajes')
    } finally {
      setIsLoading(false)
    }
  }, [ticketId])

  // Carga inicial — una sola vez gracias a useInitOnce
  useInitOnce(() => { void fetchMessages() }, `ticket-chat-${ticketId}`)

  // Auto-refresh cuando llega push notification (version bump)
  const version = useTicketDetailStore((s) => s.version)
  const prevVersion = useRef(version)
  useEffect(() => {
    if (version !== prevVersion.current && hasLoaded.current) {
      prevVersion.current = version
      void fetchMessages()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [version])

  // Scroll al fondo cuando cambian los mensajes
  useEffect(() => {
    const el = scrollRef.current
    if (el && messages.length > 0) {
      el.scrollTop = el.scrollHeight
    }
  }, [messages])

  const sendMessage = useCallback(
    async (message: string, files?: File[]) => {
      if (!ticketId || (!message.trim() && (!files || files.length === 0))) return

      setIsSending(true)
      try {
        await sendTicketMessage(ticketId, message.trim(), files)
        // El bump() del Timeline incrementa version → el useEffect refresca
      } catch {
        toast.error('Error al enviar mensaje')
      } finally {
        setIsSending(false)
      }
    },
    [ticketId],
  )

  return {
    messages,
    isLoading,
    isSending,
    sendMessage,
    refetch: fetchMessages,
    scrollRef,
    bottomRef,
  }
}
