import { KanbanBoardColumnSkeleton } from '@/components/kanban'
import { TicketKanbanCard } from '@/modules/ticket/components/TicketKanbanCard'
import {
  TicketKanbanColumns,
  type TicketKanbanRenderCardParams,
} from '@/modules/ticket/components/TicketKanbanColumns'
import { ETicketStatus, type ITicket, type ITicketStatus, type TicketStatusId } from '@/modules/ticket/schema'
import { moveTicketStatus } from '@/modules/ticket/services/TicketResource'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useMemo, useState } from 'react'

interface AdminKanbanBoardProps {
  tickets: ITicket[]
  isLoading: boolean
  hasMore: boolean
  isLoadingMore: boolean
  onLoadMore: () => Promise<void>
  onTicketsChanged: () => Promise<unknown>
  onTicketSelect: (_ticket: ITicket) => void
  selectedTicketId?: string | null
}

export function AdminKanbanBoard({
  tickets,
  isLoading,
  hasMore,
  isLoadingMore,
  onLoadMore,
  onTicketsChanged,
  onTicketSelect,
  selectedTicketId,
}: AdminKanbanBoardProps) {
  const [optimisticStatusByTicketId, setOptimisticStatusByTicketId] = useState<Record<string, string>>({})

  const columns = useMemo((): ITicketStatus[] => {
    const statusById = new Map<string, ITicketStatus>()
    for (const ticket of tickets) {
      if (ticket.ticket_status) {
        statusById.set(ticket.ticket_status_id, ticket.ticket_status)
      }
    }

    const baseColumns: ITicketStatus[] = [
      {
        id: ETicketStatus.PENDING,
        name: statusById.get(ETicketStatus.PENDING)?.name ?? 'Pendiente',
        color: statusById.get(ETicketStatus.PENDING)?.color ?? '#f59e0b',
        order: statusById.get(ETicketStatus.PENDING)?.order ?? 1,
      },
      {
        id: ETicketStatus.ATTENDED,
        name: statusById.get(ETicketStatus.ATTENDED)?.name ?? 'Atendida',
        color: statusById.get(ETicketStatus.ATTENDED)?.color ?? '#22c55e',
        order: statusById.get(ETicketStatus.ATTENDED)?.order ?? 2,
      },
    ]

    const extraColumns: ITicketStatus[] = [...statusById.values()]
      .filter((status) => ![ETicketStatus.PENDING, ETicketStatus.ATTENDED].includes(status.id as TicketStatusId))
      .map((status, index) => ({
        id: status.id,
        name: status.name ?? 'Sin nombre',
        color: status.color ?? '#94a3b8',
        order: (status.order ?? 3) + index,
      }))

    return [...baseColumns, ...extraColumns].sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  }, [tickets])

  const ticketsByStatus = useMemo(() => {
    const grouped: Record<string, ITicket[]> = {}
    for (const column of columns) {
      grouped[column.id] = []
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

  const ticketsById = useMemo(() => {
    return new Map(tickets.map((ticket) => [ticket.id, ticket]))
  }, [tickets])

  const moveTicket = useCallback(async (ticketId: string, sourceStatusId: string, targetStatusId: string) => {
    if (sourceStatusId === targetStatusId) return

    const ticket = ticketsById.get(ticketId)
    if (!ticket) return

    setOptimisticStatusByTicketId((prev) => ({ ...prev, [ticketId]: targetStatusId }))

    try {
      await moveTicketStatus(ticketId, targetStatusId, { tenantId: ticket.tenant_id ?? undefined })
      await onTicketsChanged()
      toast.success('Ticket movido exitosamente')
    } catch {
      toast.error('Error al mover el ticket')
    } finally {
      setOptimisticStatusByTicketId((prev) => {
        if (!(ticketId in prev)) return prev
        const next = { ...prev }
        delete next[ticketId]
        return next
      })
    }
  }, [ticketsById, onTicketsChanged])

  const renderLawyerCard = useCallback(({ ticket, isSelected, onTicketSelect: handleSelect }: TicketKanbanRenderCardParams) => (
    <TicketKanbanCard
      ticket={ticket}
      onClick={handleSelect}
      isSelected={isSelected}
      showCompany
    />
  ), [])

  if (isLoading) {
    return (
      <div className="flex gap-2 overflow-x-auto py-1 h-full">
        {Array.from({ length: 5 }).map((_, index) => (
          <KanbanBoardColumnSkeleton key={index} />
        ))}
      </div>
    )
  }

  if (columns.length === 0) {
    return (
      <div className="flex items-center justify-center h-full text-muted-foreground">
        No hay tickets registrados
      </div>
    )
  }

  return (
    <div className="h-full">
      <TicketKanbanColumns
        columns={columns}
        ticketsByStatus={ticketsByStatus}
        moveTicket={moveTicket}
        onTicketSelect={onTicketSelect}
        selectedTicketId={selectedTicketId}
        renderCard={renderLawyerCard}
        hasMore={hasMore}
        isLoadingMore={isLoadingMore}
        onReachColumnEnd={() => {
          void onLoadMore()
        }}
      />
    </div>
  )
}
