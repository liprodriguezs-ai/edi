import {
  KanbanBoardCard,
  KanbanBoardCardDescription,
  KanbanBoardCardTitle,
} from '@/components/kanban'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { ImportanceBadge } from '@/modules/ticket/components/ImportanceBadge'
import type { ITicket } from '@/modules/ticket/schema'
import { getTicketCompanyName } from '@/modules/ticket/utils/ticketDisplay'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayInitials } from '@/shared/utils/displayInitials'
import { CalendarDays, User } from 'lucide-react'
import { memo, useMemo } from 'react'

type CardDndData = { id: string; statusId: string }

interface TicketKanbanCardProps {
  ticket: ITicket
  onClick: (_ticket: ITicket) => void
  isSelected?: boolean
  showCompany?: boolean
}

interface TicketCardContext {
  companyName?: string
  secondaryLabel: string
  secondaryValue?: string
  createdByLabel: string
  createdByName: string
}

interface AssignedUserLike {
  full_name?: string
  name?: string
  profile_image_url?: string
}

function resolveTicketCardContext(ticket: ITicket, showCompany: boolean): TicketCardContext {
  const clientName = ticket.client?.name ?? ticket.client?.user?.full_name ?? undefined
  const companyName = getTicketCompanyName(ticket)

  return {
    companyName,
    secondaryLabel: showCompany ? 'Empresa' : 'Cliente',
    secondaryValue: showCompany ? companyName : clientName,
    createdByLabel: showCompany ? 'Solicitó' : 'Creado por',
    createdByName: ticket.user?.full_name ?? ticket.user?.name ?? 'Desconocido',
  }
}

function TicketAssignedUserSection({ assignedUser }: { assignedUser?: AssignedUserLike | null }) {
  if (!assignedUser) return null

  const displayName = assignedUser.full_name ?? assignedUser.name ?? 'Sin asignar'
  const initialsSource = assignedUser.full_name ?? assignedUser.name ?? ''

  return (
    <div className="flex items-center gap-2">
      <Avatar className="h-5 w-5 shrink-0">
        {assignedUser.profile_image_url && (
          <AvatarImage
            src={assignedUser.profile_image_url}
            alt={assignedUser.full_name ?? 'Usuario'}
          />
        )}
        <AvatarFallback className="bg-primary/10 text-primary text-[9px]">
          {displayInitials(initialsSource) || <User className="h-3 w-3" />}
        </AvatarFallback>
      </Avatar>
      <KanbanBoardCardDescription className="truncate text-muted-foreground">
        <span className="font-medium">Lo atiende:</span> {displayName}
      </KanbanBoardCardDescription>
    </div>
  )
}

function TicketSecondaryContextSection({ label, value }: { label: string; value?: string }) {
  if (!value) return null

  return (
    <KanbanBoardCardDescription className="truncate text-muted-foreground">
      <span className="font-medium">{label}:</span> {value}
    </KanbanBoardCardDescription>
  )
}

function TicketFooterSection({
  createdByLabel,
  createdByName,
  createdAt,
}: {
  createdByLabel: string
  createdByName: string
  createdAt?: string
}) {
  return (
    <div className="flex items-center justify-between w-full gap-2 pt-1 border-t border-border/50">
      <KanbanBoardCardDescription className="truncate text-muted-foreground">
        <span className="font-medium">{createdByLabel}:</span> {createdByName}
      </KanbanBoardCardDescription>
      {createdAt && (
        <span className="flex items-center gap-1 text-[10px] text-muted-foreground shrink-0">
          <CalendarDays className="h-3 w-3" />
          {displayFormatDate(createdAt, 'mediumDate')}
        </span>
      )}
    </div>
  )
}

function TicketKanbanCardInner({
  ticket,
  onClick,
  isSelected = false,
  showCompany = false,
}: TicketKanbanCardProps) {
  const dndData: CardDndData = { id: ticket.id, statusId: ticket.ticket_status_id }
  const statusColor = ticket.ticket_status?.color ?? '#7F8C8D'
  const statusName = ticket.ticket_status?.name ?? 'Sin estado'
  const assignedUser = ticket.assignments?.[0]?.user

  const context = useMemo(() => {
    return resolveTicketCardContext(ticket, showCompany)
  }, [ticket, showCompany])

  return (
    <KanbanBoardCard
      data={dndData}
      className={`w-full transition-shadow ${isSelected ? 'ring-2 ring-primary shadow-md' : ''}`}
    >
      <div
        role="button"
        tabIndex={0}
        className="flex flex-col gap-2 w-full text-left"
        onClick={() => onClick(ticket)}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            onClick(ticket)
          }
        }}
      >
        <div className="flex items-center justify-between w-full gap-2">
          <span className="text-[11px] text-muted-foreground font-mono">{ticket.folio}</span>
          <Badge
            variant="outline"
            className="text-[10px] font-medium flex items-center gap-1 shrink-0"
          >
            <span
              className="inline-block size-2 rounded-full shrink-0"
              style={{ backgroundColor: statusColor }}
            />
            {statusName}
          </Badge>
        </div>

        <KanbanBoardCardTitle className="line-clamp-2 leading-snug">
          {ticket.title}
        </KanbanBoardCardTitle>

        {showCompany && context.companyName && (
          <Badge variant="secondary" className="w-fit text-[10px]">
            {context.companyName}
          </Badge>
        )}

        <div className="flex items-center gap-1">
          <ImportanceBadge
            importanceId={ticket.ticket_importance_id}
            importance={ticket.ticket_importance}
          />
        </div>

        <TicketAssignedUserSection assignedUser={assignedUser} />
        <TicketSecondaryContextSection label={context.secondaryLabel} value={context.secondaryValue} />
        <TicketFooterSection
          createdByLabel={context.createdByLabel}
          createdByName={context.createdByName}
          createdAt={ticket.created_at}
        />
      </div>
    </KanbanBoardCard>
  )
}

export const TicketKanbanCard = memo(TicketKanbanCardInner)
