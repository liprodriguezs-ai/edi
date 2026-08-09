import {
  KanbanBoardColumnSkeleton,
} from '@/components/kanban'
import { TicketKanbanColumns } from '@/modules/ticket/components/TicketKanbanColumns'
import type { ITicket } from '@/modules/ticket/schema'
import { useTicketKanban } from '@/modules/ticket/hooks/useTicketKanban'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'

interface TicketKanbanBoardProps {
  onTicketSelect: (_ticket: ITicket) => void
  selectedTicketId?: string | null
  searchTerm: string
}

export const TicketKanbanBoard = ({ onTicketSelect, selectedTicketId, searchTerm }: TicketKanbanBoardProps) => {
  const { columns, ticketsByStatus, hasMore, isLoading, isLoadingMore, loadMore, moveTicket } = useTicketKanban(searchTerm)
  const { hasRole } = useRoles()
  const isLawyer = hasRole(ERoleUserSlug.LAWYER)

  if (isLoading) {
    return (
      <div className="flex gap-2 overflow-x-auto py-1 h-full">
        {Array.from({ length: 4 }).map((_, i) => (
          <KanbanBoardColumnSkeleton key={i} />
        ))}
      </div>
    )
  }

  if (columns.length === 0) {
    return (
      <div className="flex items-center justify-center rounded-lg border border-dashed p-12 h-full">
        <div className="text-center">
          <h3 className="text-lg font-semibold">Sin estatus configurados</h3>
          <p className="mt-2 text-sm text-muted-foreground">
            No hay estatus de ticket disponibles para mostrar el tablero.
          </p>
        </div>
      </div>
    )
  }

  return (
    <TicketKanbanColumns
      columns={columns}
      ticketsByStatus={ticketsByStatus}
      moveTicket={moveTicket}
      onTicketSelect={onTicketSelect}
      selectedTicketId={selectedTicketId}
      isDragDisabled={!isLawyer}
      hasMore={hasMore}
      isLoadingMore={isLoadingMore}
      onReachColumnEnd={() => {
        void loadMore()
      }}
    />
  )
}
