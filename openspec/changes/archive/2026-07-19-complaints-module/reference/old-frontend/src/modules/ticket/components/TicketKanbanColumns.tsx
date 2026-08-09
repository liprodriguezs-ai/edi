import {
  KanbanBoard,
  KanbanBoardColumn,
  KanbanBoardColumnHeader,
  KanbanBoardColumnList,
  KanbanBoardColumnListItem,
  KanbanBoardColumnTitle,
  KanbanBoardExtraMargin,
  KanbanBoardProvider,
  useDndEvents,
  type KanbanBoardDropDirection,
} from '@/components/kanban'
import { Badge } from '@/components/ui/badge'
import { TicketKanbanCard } from '@/modules/ticket/components/TicketKanbanCard'
import type { ITicket, ITicketStatus } from '@/modules/ticket/schema'
import type { ReactNode, UIEvent } from 'react'
import { useCallback, useEffect, useRef } from 'react'
import { TICKET_KANBAN_SCROLL_THRESHOLD } from '@/modules/ticket/utils/ticketKanban'

type CardDndData = { id: string; statusId: string }

export interface TicketKanbanRenderCardParams {
  ticket: ITicket
  isSelected: boolean
  onTicketSelect: (_ticket: ITicket) => void
}

type TicketKanbanRenderCard = (_params: TicketKanbanRenderCardParams) => ReactNode

interface TicketKanbanColumnsProps {
  columns: ITicketStatus[]
  ticketsByStatus: Record<string, ITicket[]>
  moveTicket: (_ticketId: string, _sourceStatusId: string, _targetStatusId: string) => void
  onTicketSelect: (_ticket: ITicket) => void
  selectedTicketId?: string | null
  isDragDisabled?: boolean
  renderCard?: TicketKanbanRenderCard
  onReachColumnEnd?: () => void
  hasMore?: boolean
  isLoadingMore?: boolean
}

export function TicketKanbanColumns({
  columns,
  ticketsByStatus,
  moveTicket,
  onTicketSelect,
  selectedTicketId,
  isDragDisabled = false,
  renderCard,
  onReachColumnEnd,
  hasMore = false,
  isLoadingMore = false,
}: TicketKanbanColumnsProps) {
  const renderTicketCard: TicketKanbanRenderCard = renderCard ?? ((params) => (
    <TicketKanbanCard
      ticket={params.ticket}
      onClick={params.onTicketSelect}
      isSelected={params.isSelected}
    />
  ))

  return (
    <KanbanBoardProvider>
      <KanbanBoard className="h-full pb-4">
        {columns.map((status) => (
          <TicketStatusColumn
            key={status.id}
            status={status}
            tickets={ticketsByStatus[status.id] ?? []}
            moveTicket={moveTicket}
            onTicketSelect={onTicketSelect}
            selectedTicketId={selectedTicketId}
            isDragDisabled={isDragDisabled}
            renderCard={renderTicketCard}
            onReachColumnEnd={onReachColumnEnd}
            hasMore={hasMore}
            isLoadingMore={isLoadingMore}
          />
        ))}
        <KanbanBoardExtraMargin />
      </KanbanBoard>
    </KanbanBoardProvider>
  )
}

interface TicketStatusColumnProps {
  status: ITicketStatus
  tickets: ITicket[]
  moveTicket: (_ticketId: string, _sourceStatusId: string, _targetStatusId: string) => void
  onTicketSelect: (_ticket: ITicket) => void
  selectedTicketId?: string | null
  isDragDisabled: boolean
  renderCard: TicketKanbanRenderCard
  onReachColumnEnd?: () => void
  hasMore: boolean
  isLoadingMore: boolean
}

function TicketStatusColumn({
  status,
  tickets,
  moveTicket,
  onTicketSelect,
  selectedTicketId,
  isDragDisabled,
  renderCard,
  onReachColumnEnd,
  hasMore,
  isLoadingMore,
}: TicketStatusColumnProps) {
  const { onDragCancel, onDragEnd } = useDndEvents()
  const listRef = useRef<HTMLUListElement | null>(null)

  const handleDropOverColumn = useCallback((dataTransferData: string) => {
    const data = JSON.parse(dataTransferData) as CardDndData
    if (data.statusId === status.id) return
    moveTicket(data.id, data.statusId, status.id)
  }, [status.id, moveTicket])

  const handleDropOverListItem = useCallback((targetTicketId: string) => {
    return (dataTransferData: string, _dropDirection: KanbanBoardDropDirection) => {
      const data = JSON.parse(dataTransferData) as CardDndData

      if (data.id === targetTicketId) {
        onDragCancel(data.id)
        return
      }

      if (data.statusId !== status.id) {
        moveTicket(data.id, data.statusId, status.id)
        onDragEnd(data.id, targetTicketId)
      }
    }
  }, [status.id, moveTicket, onDragCancel, onDragEnd])

  const handleListScroll = useCallback((event: UIEvent<HTMLUListElement>) => {
    if (!onReachColumnEnd) {
      return
    }

    const { scrollHeight, scrollTop, clientHeight } = event.currentTarget
    const remainingDistance = scrollHeight - scrollTop - clientHeight

    if (remainingDistance <= TICKET_KANBAN_SCROLL_THRESHOLD) {
      onReachColumnEnd()
    }
  }, [onReachColumnEnd])

  useEffect(() => {
    if (!onReachColumnEnd || !hasMore || isLoadingMore || tickets.length === 0) {
      return
    }

    const listElement = listRef.current
    if (!listElement) {
      return
    }

    const remainingDistance = listElement.scrollHeight - listElement.clientHeight

    if (remainingDistance <= TICKET_KANBAN_SCROLL_THRESHOLD) {
      onReachColumnEnd()
    }
  }, [hasMore, isLoadingMore, onReachColumnEnd, tickets.length])

  return (
    <KanbanBoardColumn
      columnId={status.id}
      onDropOverColumn={isDragDisabled ? undefined : handleDropOverColumn}
      className="w-72 min-w-[18rem]"
    >
      <KanbanBoardColumnHeader>
        <KanbanBoardColumnTitle columnId={status.id}>
          <div className="mr-2 size-2 rounded-full" style={{ backgroundColor: status.color }} />
          <span className="truncate max-w-[140px]">{status.name}</span>
          <Badge variant="secondary" className="ml-2 text-xs">
            {tickets.length}
          </Badge>
        </KanbanBoardColumnTitle>
      </KanbanBoardColumnHeader>

      <KanbanBoardColumnList ref={listRef} onScroll={handleListScroll}>
        {tickets.map((ticket) => (
          <KanbanBoardColumnListItem
            key={ticket.id}
            cardId={ticket.id}
            onDropOverListItem={isDragDisabled ? undefined : handleDropOverListItem(ticket.id)}
          >
            {isDragDisabled ? (
              <div
                onDragStartCapture={(event) => {
                  event.preventDefault()
                  event.stopPropagation()
                }}
                className="cursor-default"
              >
                {renderCard({
                  ticket,
                  isSelected: selectedTicketId === ticket.id,
                  onTicketSelect,
                })}
              </div>
            ) : (
              renderCard({
                ticket,
                isSelected: selectedTicketId === ticket.id,
                onTicketSelect,
              })
            )}
          </KanbanBoardColumnListItem>
        ))}
      </KanbanBoardColumnList>
    </KanbanBoardColumn>
  )
}
