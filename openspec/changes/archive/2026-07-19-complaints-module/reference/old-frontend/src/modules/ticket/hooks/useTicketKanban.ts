import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ITicket } from '@/modules/ticket/schema'
import { TicketResource, moveTicketStatus } from '@/modules/ticket/services/TicketResource'
import { useTicketStatusCatalog } from '@/modules/ticket/hooks/useTicketStatusCatalog'
import { useTicketStatusStore } from '@/modules/ticket/store/ticketStatusStore'
import {
  buildTicketKanbanSearch,
  mergeUniqueTickets,
  TICKET_KANBAN_INCLUDES,
  TICKET_KANBAN_PAGE_SIZE,
  TICKET_KANBAN_SORT,
} from '@/modules/ticket/utils/ticketKanban'
import { toast } from '@/shared/hooks/useToast'

export function useTicketKanban(searchTerm: string) {
  useTicketStatusCatalog()

  const statuses = useTicketStatusStore((s) => s.entities)
  const isLoadingStatuses = useTicketStatusStore((s) => s.isLoading)

  const [tickets, setTickets] = useState<ITicket[]>([])
  const [isLoadingTickets, setIsLoadingTickets] = useState(false)
  const [isLoadingMore, setIsLoadingMore] = useState(false)
  const [hasMore, setHasMore] = useState(false)
  const [optimisticStatusByTicketId, setOptimisticStatusByTicketId] = useState<Record<string, string>>({})

  const lastSearchValueRef = useRef<string | null | undefined>(undefined)
  const loadedPagesRef = useRef(0)
  const requestIdRef = useRef(0)
  const isLoadingMoreRef = useRef(false)

  const isLoading = isLoadingTickets || isLoadingStatuses

  const columns = useMemo(() => {
    return [...statuses].sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  }, [statuses])

  const fetchPage = useCallback(async (
    page: number,
    normalizedSearch: string | null,
    mode: 'replace' | 'append',
  ) => {
    const requestId = ++requestIdRef.current
    const search = buildTicketKanbanSearch(normalizedSearch)

    if (mode === 'replace') {
      setIsLoadingTickets(true)
    } else {
      isLoadingMoreRef.current = true
      setIsLoadingMore(true)
    }

    try {
      const response = await TicketResource.search({
        page,
        limit: TICKET_KANBAN_PAGE_SIZE,
        sort: TICKET_KANBAN_SORT,
        includes: TICKET_KANBAN_INCLUDES,
        ...(search ? { search } : {}),
      })

      if (requestId !== requestIdRef.current) {
        return
      }

      const nextTickets = response.data ?? []
      const nextPageCount = response.meta?.current_page ?? page
      const totalPages = response.meta?.last_page ?? nextPageCount

      setTickets((prev) => {
        if (mode === 'append') {
          return mergeUniqueTickets(prev, nextTickets)
        }

        return nextTickets
      })
      loadedPagesRef.current = nextPageCount
      setHasMore(nextPageCount < totalPages)
    } catch {
      if (requestId !== requestIdRef.current) {
        return
      }

      if (mode === 'replace') {
        setTickets([])
        setHasMore(false)
        loadedPagesRef.current = 0
        toast.error('Error al obtener la lista')
      } else {
        toast.error('No se pudieron cargar más tickets')
      }
    } finally {
      if (requestId === requestIdRef.current) {
        if (mode === 'replace') {
          setIsLoadingTickets(false)
        } else {
          isLoadingMoreRef.current = false
          setIsLoadingMore(false)
        }
      }
    }
  }, [])

  const refreshLoadedPages = useCallback(async () => {
    const pagesToReload = loadedPagesRef.current || 1
    const normalizedSearch = lastSearchValueRef.current ?? null
    const requestId = ++requestIdRef.current

    setIsLoadingTickets(true)

    try {
      let mergedTickets: ITicket[] = []
      let currentLoadedPages = 0
      let currentTotalPages = 1

      for (let page = 1; page <= pagesToReload; page += 1) {
        const response = await TicketResource.search({
          page,
          limit: TICKET_KANBAN_PAGE_SIZE,
          sort: TICKET_KANBAN_SORT,
          includes: TICKET_KANBAN_INCLUDES,
          ...(normalizedSearch ? { search: buildTicketKanbanSearch(normalizedSearch) } : {}),
        })

        if (requestId !== requestIdRef.current) {
          return
        }

        mergedTickets = mergeUniqueTickets(mergedTickets, response.data ?? [])
        currentLoadedPages = response.meta?.current_page ?? page
        currentTotalPages = response.meta?.last_page ?? currentLoadedPages

        if (currentLoadedPages >= currentTotalPages) {
          break
        }
      }

      setTickets(mergedTickets)
      loadedPagesRef.current = currentLoadedPages
      setHasMore(currentLoadedPages < currentTotalPages)
    } catch {
      if (requestId === requestIdRef.current) {
        toast.error('Error al refrescar los tickets')
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setIsLoadingTickets(false)
      }
    }
  }, [])

  useEffect(() => {
    const normalizedSearch = searchTerm.trim() || null

    if (lastSearchValueRef.current === normalizedSearch) {
      return
    }

    const timeoutId = window.setTimeout(() => {
      lastSearchValueRef.current = normalizedSearch
      loadedPagesRef.current = 0
      void fetchPage(1, normalizedSearch, 'replace')
    }, 300)

    return () => {
      window.clearTimeout(timeoutId)
    }
  }, [searchTerm, fetchPage])

  const loadMore = useCallback(async () => {
    if (isLoadingTickets || isLoadingMoreRef.current || !hasMore) {
      return
    }

    await fetchPage(loadedPagesRef.current + 1, lastSearchValueRef.current ?? null, 'append')
  }, [fetchPage, hasMore, isLoadingTickets])

  const ticketsByStatus = useMemo(() => {
    const grouped: Record<string, ITicket[]> = {}

    for (const status of columns) {
      grouped[status.id] = []
    }

    for (const ticket of tickets) {
      const statusId = optimisticStatusByTicketId[ticket.id] ?? ticket.ticket_status_id
      const normalizedTicket = statusId === ticket.ticket_status_id
        ? ticket
        : { ...ticket, ticket_status_id: statusId }

      if (grouped[statusId]) {
        grouped[statusId].push(normalizedTicket)
      } else {
        grouped[statusId] = [normalizedTicket]
      }
    }

    return grouped
  }, [tickets, columns, optimisticStatusByTicketId])

  const moveTicket = useCallback(
    async (ticketId: string, sourceStatusId: string, targetStatusId: string) => {
      if (sourceStatusId === targetStatusId) return

      setOptimisticStatusByTicketId((prev) => ({
        ...prev,
        [ticketId]: targetStatusId,
      }))

      try {
        await moveTicketStatus(ticketId, targetStatusId)
        toast.success('Ticket movido exitosamente')
        await refreshLoadedPages()
      } catch {
        toast.error('Error al mover el ticket')
      } finally {
        setOptimisticStatusByTicketId((prev) => {
          if (!(ticketId in prev)) {
            return prev
          }

          const next = { ...prev }
          delete next[ticketId]
          return next
        })
      }
    },
    [refreshLoadedPages],
  )

  return {
    columns,
    ticketsByStatus,
    isLoading,
    isLoadingMore,
    hasMore,
    loadMore,
    moveTicket,
  }
}
