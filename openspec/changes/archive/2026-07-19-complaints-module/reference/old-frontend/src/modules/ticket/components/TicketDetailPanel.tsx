import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import type { ITicket } from '@/modules/ticket/schema'
import { ImportanceBadge } from '@/modules/ticket/components/ImportanceBadge'
import { TicketActivityLog } from '@/modules/ticket/components/TicketActivityLog'
import { getTicketCompanyName } from '@/modules/ticket/utils/ticketDisplay'
import { useSession } from '@/shared/auth/useSession'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ArrowRight, Ticket, User, X } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

interface TicketDetailPanelProps {
  ticket: ITicket | null
  onClose: () => void
}

interface TicketDetailPanelContext {
  creatorLabel: string
  creatorName: string
  secondaryLabel: string
  secondaryValue?: string
}

interface TicketDetailPanelContentProps {
  ticket: ITicket
  onClose: () => void
  context: TicketDetailPanelContext
}

function resolveTicketDetailPanelContext(params: {
  ticket: ITicket
  isLawyer: boolean
  contextCompanyName?: string | null
  sessionCompanyName?: string
  sessionTenantName?: string
}): TicketDetailPanelContext {
  const { ticket, isLawyer, contextCompanyName, sessionCompanyName, sessionTenantName } = params
  const clientName = ticket.client?.name ?? ticket.client?.user?.full_name ?? undefined
  const companyName = getTicketCompanyName(ticket, {
    contextCompanyName,
    sessionCompanyName,
    sessionTenantName,
  })

  return {
    creatorLabel: isLawyer ? 'Solicitó' : 'Creado por',
    creatorName: ticket.user?.full_name ?? ticket.user?.name ?? 'Desconocido',
    secondaryLabel: companyName ? 'Empresa' : 'Cliente',
    secondaryValue: companyName ?? clientName,
  }
}

export function TicketDetailPanel({ ticket, onClose }: TicketDetailPanelProps) {
  const { hasRole } = useRoles()
  const { company, profile } = useSession()
  const contextCompanyName = useTenantContextStore((state) => state.tenantName)
  const isLawyer = hasRole(ERoleUserSlug.LAWYER)

  if (!ticket) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 border-l bg-background p-6">
        <Ticket className="size-10 text-muted-foreground/40" />
        <p className="text-sm text-muted-foreground">
          Selecciona un ticket para ver el detalle
        </p>
      </div>
    )
  }

  const sessionCompanyName = typeof company?.name === 'string' ? company.name : undefined
  const sessionTenantName = profile?.tenant?.name
  const context = resolveTicketDetailPanelContext({
    ticket,
    isLawyer,
    contextCompanyName,
    sessionCompanyName,
    sessionTenantName,
  })

  return <TicketDetailPanelContent ticket={ticket} onClose={onClose} context={context} />
}

function TicketDetailPanelContent({ ticket, onClose, context }: TicketDetailPanelContentProps) {
  const navigate = useNavigate()

  const statusColor = ticket.ticket_status?.color ?? '#94a3b8'
  const assignedUser = ticket.assignments?.[0]?.user

  return (
    <div className="flex h-full flex-col border-l bg-background">
      <div className="flex items-center justify-between p-4">
        <span className="font-mono text-xs text-muted-foreground">
          {ticket.folio}
        </span>
        <Button variant="ghost" size="icon" onClick={onClose}>
          <X className="size-4" />
        </Button>
      </div>

      <div className="flex-1 overflow-y-auto px-4 pb-4">
        <h2 className="text-lg font-semibold leading-tight">
          {ticket.title}
        </h2>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Badge variant="outline" className="flex items-center gap-1.5 text-xs">
            <span
              className="size-2 rounded-full"
              style={{ backgroundColor: statusColor }}
            />
            {ticket.ticket_status?.name ?? 'Sin estado'}
          </Badge>
          <ImportanceBadge
            importanceId={ticket.ticket_importance_id}
            importance={ticket.ticket_importance}
          />
        </div>

        <div className="mt-4">
          <p className="line-clamp-4 text-sm leading-relaxed text-foreground">
            {ticket.description ?? 'Sin descripción'}
          </p>
        </div>

        <div className="mt-4 space-y-2">
          <div className="flex items-center gap-2 text-sm">
            <User className="size-3.5 shrink-0 text-muted-foreground" />
            <span className="text-muted-foreground">Asignado a:</span>
            <span className="font-medium">
              {assignedUser?.full_name ?? assignedUser?.name ?? 'Sin asignar'}
            </span>
          </div>

          {context.secondaryValue && (
            <div className="flex items-center gap-2 text-sm">
              <span className="ml-5.5 text-muted-foreground">{context.secondaryLabel}:</span>
              <span className="font-medium">{context.secondaryValue}</span>
            </div>
          )}

          <div className="flex items-center gap-2 text-sm">
            <span className="ml-5.5 text-muted-foreground">{context.creatorLabel}:</span>
            <span className="font-medium">
              {context.creatorName}
              {ticket.created_at && (
                <span className="ml-1 font-normal text-muted-foreground">
                  · {displayFormatDate(ticket.created_at)}
                </span>
              )}
            </span>
          </div>
        </div>

        <Separator className="my-4" />

        <div className="space-y-3">
          <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Actividad
          </label>
          <TicketActivityLog ticketId={ticket.id} />
        </div>
      </div>

      <div className="shrink-0 border-t bg-background p-4">
        <Button
          className="w-full"
          onClick={() => navigate(`/ticket/${ticket.id}`)}
        >
          Ver detalle completo
          <ArrowRight className="ml-2 size-4" />
        </Button>
      </div>
    </div>
  )
}
