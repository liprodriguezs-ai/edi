import { useCallback, useEffect, useRef, useState } from 'react'
import type { ITicket } from '@/modules/ticket/schema'
import { mergeUniqueTickets, TICKET_KANBAN_PAGE_SIZE } from '@/modules/ticket/utils/ticketKanban'
import type { IPaginationMeta } from '@/shared/interfaces/Entity'
import { apiClient } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'

export interface AdminTicketFilters {
  tenant_id?: string
  search?: string
  page?: number
  per_page?: number
  status_id?: string
  importance_id?: string
}

function buildAdminTicketQueryString(filters: AdminTicketFilters, page: number): string {
  const params = new URLSearchParams()

  if (filters.tenant_id) params.set('tenant_id', filters.tenant_id)
  if (filters.search) params.set('search', filters.search)
  if (filters.status_id) params.set('status_id', filters.status_id)
  if (filters.importance_id) params.set('importance_id', filters.importance_id)
  params.set('page', String(page))
  params.set('per_page', String(filters.per_page ?? TICKET_KANBAN_PAGE_SIZE))

  return params.toString()
}

export function useAdminTickets() {
  const [tickets, setTickets] = useState<ITicket[]>([])
  const [meta, setMeta] = useState<IPaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [isLoadingMore, setIsLoadingMore] = useState(false)
  const [hasMore, setHasMore] = useState(false)
  const [filters, setFilters] = useState<AdminTicketFilters>({ page: 1, per_page: TICKET_KANBAN_PAGE_SIZE })

  const loadedPagesRef = useRef(0)
  const requestIdRef = useRef(0)
  const isLoadingMoreRef = useRef(false)
  const filtersRef = useRef(filters)
  filtersRef.current = filters

  const fetchPage = useCallback(async (
    page: number,
    currentFilters: AdminTicketFilters,
    mode: 'replace' | 'append',
  ) => {
    const requestId = ++requestIdRef.current
    const queryString = buildAdminTicketQueryString(currentFilters, page)

    if (mode === 'replace') {
      setIsLoading(true)
    } else {
      isLoadingMoreRef.current = true
      setIsLoadingMore(true)
    }

    try {
      const { data } = await apiClient.get(`/admin/tickets?${queryString}`)

      if (requestId !== requestIdRef.current) {
        return
      }

      const nextTickets = data.data ?? []
      const nextMeta = data.meta ?? null
      const nextLoadedPages = nextMeta?.current_page ?? page
      const nextHasMore = nextMeta ? nextLoadedPages < nextMeta.last_page : false

      setTickets((prev) => {
        if (mode === 'append') {
          return mergeUniqueTickets(prev, nextTickets)
        }

        return nextTickets
      })
      setMeta(nextMeta)
      setHasMore(nextHasMore)
      loadedPagesRef.current = nextLoadedPages
    } catch {
      if (requestId !== requestIdRef.current) {
        return
      }

      if (mode === 'replace') {
        setTickets([])
        setMeta(null)
        setHasMore(false)
        loadedPagesRef.current = 0
        toast.error('Error al obtener la lista')
      } else {
        toast.error('No se pudieron cargar más tickets')
      }
    } finally {
      if (requestId === requestIdRef.current) {
        if (mode === 'replace') {
          setIsLoading(false)
        } else {
          isLoadingMoreRef.current = false
          setIsLoadingMore(false)
        }
      }
    }
  }, [])

  const refetch = useCallback(async () => {
    const currentFilters = filtersRef.current
    const pagesToReload = loadedPagesRef.current || 1
    const requestId = ++requestIdRef.current

    setIsLoading(true)

    try {
      let mergedTickets: ITicket[] = []
      let lastMeta: IPaginationMeta | null = null
      let currentLoadedPages = 0

      for (let page = 1; page <= pagesToReload; page += 1) {
        const queryString = buildAdminTicketQueryString(currentFilters, page)
        const { data } = await apiClient.get(`/admin/tickets?${queryString}`)

        if (requestId !== requestIdRef.current) {
          return
        }

        mergedTickets = mergeUniqueTickets(mergedTickets, data.data ?? [])
        lastMeta = data.meta ?? null
        currentLoadedPages = lastMeta?.current_page ?? page

        if (lastMeta && currentLoadedPages >= lastMeta.last_page) {
          break
        }
      }

      setTickets(mergedTickets)
      setMeta(lastMeta)
      setHasMore(lastMeta ? currentLoadedPages < lastMeta.last_page : false)
      loadedPagesRef.current = currentLoadedPages
    } catch {
      if (requestId === requestIdRef.current) {
        toast.error('Error al refrescar los tickets')
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setIsLoading(false)
      }
    }
  }, [])

  useEffect(() => {
    loadedPagesRef.current = 0
    void fetchPage(1, filters, 'replace')
  }, [fetchPage, filters])

  const updateFilters = useCallback((newFilters: Partial<AdminTicketFilters>) => {
    setFilters((prev) => ({
      ...prev,
      ...newFilters,
      page: 1,
      per_page: newFilters.per_page ?? prev.per_page ?? TICKET_KANBAN_PAGE_SIZE,
    }))
  }, [])

  const loadMore = useCallback(async () => {
    if (isLoading || isLoadingMoreRef.current || !hasMore) {
      return
    }

    await fetchPage(loadedPagesRef.current + 1, filtersRef.current, 'append')
  }, [fetchPage, hasMore, isLoading])

  return {
    tickets,
    meta,
    isLoading,
    isLoadingMore,
    hasMore,
    filters,
    updateFilters,
    loadMore,
    refetch,
  }
}
