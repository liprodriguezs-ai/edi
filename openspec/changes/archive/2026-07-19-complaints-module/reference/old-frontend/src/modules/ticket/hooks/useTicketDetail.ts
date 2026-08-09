import type { ITicket } from '@/modules/ticket/schema'
import { TicketResource } from '@/modules/ticket/services/TicketResource'
import { useTicketDetailStore } from '@/modules/ticket/store/ticketDetailStore'
import { useInitOnce } from '@/shared/hooks/useInitOnce'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useEffect, useRef, useState } from 'react'

export function useTicketDetail(ticketId: string | null) {
  const [ticket, setTicket] = useState<ITicket | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const hasLoaded = useRef(false)

  const fetchTicket = useCallback(async () => {
    if (!ticketId) return
    if (!hasLoaded.current) {
      setIsLoading(true)
    }
    try {
      const response = await TicketResource.show(ticketId, {
        include:
          'ticket_status,ticket_importance,user,client,client.user,assignments,assignments.user,messages,messages.user',
      } as Record<string, unknown>)
      setTicket((response as ITicket) ?? null)
      hasLoaded.current = true
    } catch {
      toast.error('Error al cargar el ticket')
    } finally {
      setIsLoading(false)
    }
  }, [ticketId])

  useInitOnce(() => { void fetchTicket() }, `ticket-detail-${ticketId}`)

  // Auto-refresh cuando llega push notification (version bump)
  const version = useTicketDetailStore((s) => s.version)
  const prevVersion = useRef(version)
  useEffect(() => {
    if (version !== prevVersion.current && hasLoaded.current) {
      prevVersion.current = version
      void fetchTicket()
    }
  }, [version, fetchTicket])

  const updateTicketLocal = useCallback((updates: Partial<ITicket>) => {
    setTicket((prev) => (prev ? { ...prev, ...updates } : null))
  }, [])

  return { ticket, isLoading, refetch: fetchTicket, updateTicketLocal }
}
