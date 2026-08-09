import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { TicketActivityLog } from '@/modules/ticket/components/TicketActivityLog'
import type { ITicket, ITicketStatus } from '@/modules/ticket/schema'
import { getTicketCompanyName } from '@/modules/ticket/utils/ticketDisplay'
import { useTicketImportanceStore } from '@/modules/ticket/store/ticketImportanceStore'
import { useSession } from '@/shared/auth/useSession'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayInitials } from '@/shared/utils/displayInitials'
import { UserPlus } from 'lucide-react'

interface TicketPropertiesProps {
  ticket: ITicket
  statuses: ITicketStatus[]
  onStatusChange: (_statusId: string) => void
  onImportanceChange: (_importanceId: string) => void
  onAssignMe: () => void
  isLawyer: boolean
}

interface TicketDetailsContext {
  creatorName: string
  createdByLabel: string
  customerLabel: string
  customerValue: string
  showCustomerRow: boolean
}

function SectionLabel({ children }: { children: string }) {
  return (
    <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      {children}
    </label>
  )
}

function TicketStatusField({
  isLawyer,
  statuses,
  ticketStatusId,
  currentStatus,
  onStatusChange,
}: {
  isLawyer: boolean
  statuses: ITicketStatus[]
  ticketStatusId?: string
  currentStatus?: ITicketStatus
  onStatusChange: (_statusId: string) => void
}) {
  if (isLawyer) {
    if (statuses.length === 0) {
      return <Skeleton className="h-9 w-full" />
    }

    return (
      <Select value={ticketStatusId ?? ''} onValueChange={onStatusChange}>
        <SelectTrigger className="w-full">
          <SelectValue placeholder="Seleccionar estatus" />
        </SelectTrigger>
        <SelectContent>
          {statuses.map((status) => (
            <SelectItem key={status.id} value={status.id}>
              <div className="flex items-center gap-2">
                <span
                  className="size-2 shrink-0 rounded-full"
                  style={{ backgroundColor: status.color }}
                />
                {status.name}
              </div>
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  return (
    <div className="flex items-center gap-2 rounded-lg bg-muted/30 px-3 py-2">
      {currentStatus && (
        <span
          className="size-2 shrink-0 rounded-full"
          style={{ backgroundColor: currentStatus.color }}
        />
      )}
      <span className="text-sm font-medium">
        {currentStatus?.name ?? 'Sin estatus'}
      </span>
    </div>
  )
}

function TicketImportanceField({
  isLawyer,
  ticketImportanceId,
  importances,
  currentImportance,
  onImportanceChange,
}: {
  isLawyer: boolean
  ticketImportanceId?: string
  importances: Array<{ id: string; name?: string; color?: string }>
  currentImportance?: { name?: string; color?: string } | null
  onImportanceChange: (_importanceId: string) => void
}) {
  if (isLawyer) {
    return (
      <Select value={ticketImportanceId ?? ''} onValueChange={onImportanceChange}>
        <SelectTrigger className="w-full">
          <SelectValue placeholder="Seleccionar prioridad" />
        </SelectTrigger>
        <SelectContent>
          {importances.map((option) => (
            <SelectItem key={option.id} value={option.id}>
              <div className="flex items-center gap-2">
                <span
                  className="size-2 shrink-0 rounded-full"
                  style={{ backgroundColor: option.color }}
                />
                {option.name}
              </div>
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  return (
    <div className="flex items-center gap-2 rounded-lg bg-muted/30 px-3 py-2">
      {currentImportance && (
        <span
          className="size-2 shrink-0 rounded-full"
          style={{ backgroundColor: currentImportance.color }}
        />
      )}
      <span className="text-sm font-medium">
        {currentImportance?.name ?? 'Sin prioridad'}
      </span>
    </div>
  )
}

function TicketAssignmentField({
  assignedUser,
  isLawyer,
  onAssignMe,
}: {
  assignedUser?: ITicket['assignments'] extends Array<infer Assignment>
    ? Assignment extends { user?: infer User }
      ? User
      : never
    : never
  isLawyer: boolean
  onAssignMe: () => void
}) {
  if (assignedUser) {
    return (
      <div className="flex items-center gap-2.5 rounded-lg bg-muted/30 px-3 py-2">
        <Avatar className="h-7 w-7">
          {assignedUser.profile_image_url && (
            <AvatarImage
              src={assignedUser.profile_image_url}
              alt={assignedUser.full_name ?? assignedUser.name}
            />
          )}
          <AvatarFallback className="bg-primary/10 text-primary text-[10px]">
            {displayInitials(assignedUser.full_name ?? assignedUser.name)}
          </AvatarFallback>
        </Avatar>
        <span className="text-sm font-medium">
          {assignedUser.full_name ?? assignedUser.name}
        </span>
      </div>
    )
  }

  if (isLawyer) {
    return (
      <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border/60 px-4 py-4">
        <span className="text-sm text-muted-foreground">Sin asignar</span>
        <Button
          variant="outline"
          size="sm"
          onClick={onAssignMe}
          className="transition-colors"
        >
          <UserPlus className="size-3.5 mr-1" />
          Asignarme
        </Button>
      </div>
    )
  }

  return (
    <div className="flex items-center gap-2 rounded-lg bg-muted/30 px-3 py-2">
      <span className="text-sm font-medium text-muted-foreground">Sin asignar</span>
    </div>
  )
}

function TicketDetailRow({
  label,
  value,
  valueClassName = 'text-sm font-medium',
}: {
  label: string
  value: React.ReactNode
  valueClassName?: string
}) {
  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className={valueClassName}>{value}</span>
    </div>
  )
}

function TicketDetailsCard({
  folio,
  createdAt,
  context,
}: {
  folio: string
  createdAt?: string
  context: TicketDetailsContext
}) {
  return (
    <div className="rounded-lg bg-muted/30 p-3 space-y-0">
      <TicketDetailRow
        label="Folio"
        value={folio}
        valueClassName="text-sm font-mono font-semibold text-primary"
      />
      <TicketDetailRow label={context.createdByLabel} value={context.creatorName} />
      <TicketDetailRow label="Fecha" value={displayFormatDate(createdAt)} />
      {context.showCustomerRow && (
        <TicketDetailRow label={context.customerLabel} value={context.customerValue} />
      )}
    </div>
  )
}

function resolveTicketDetailsContext(params: {
  ticket: ITicket
  isLawyer: boolean
  tenantName?: string | null
  sessionCompanyName?: string
  sessionTenantName?: string
}): TicketDetailsContext {
  const { ticket, isLawyer, tenantName, sessionCompanyName, sessionTenantName } = params
  const creatorName = ticket.user?.full_name ?? ticket.user?.name ?? 'Desconocido'
  const createdByLabel = isLawyer ? 'Solicitó' : 'Creado por'
  const clientName = ticket.client?.name
  const companyName = getTicketCompanyName(ticket, {
    contextCompanyName: tenantName,
    sessionCompanyName,
    sessionTenantName,
  })
  const customerLabel = companyName ? 'Empresa' : 'Cliente'
  const customerValue = companyName ?? clientName ?? '\u2014'

  return {
    creatorName,
    createdByLabel,
    customerLabel,
    customerValue,
    showCustomerRow: customerValue !== '\u2014',
  }
}

export function TicketProperties({
  ticket,
  statuses,
  onStatusChange,
  onImportanceChange,
  onAssignMe,
  isLawyer,
}: TicketPropertiesProps) {
  const { company, profile } = useSession()
  const importances = useTicketImportanceStore((s) => s.entities)
  const tenantName = useTenantContextStore((s) => s.tenantName)
  const assignedUser = ticket.assignments?.[0]?.user
  const sessionCompanyName = typeof company?.name === 'string' ? company.name : undefined
  const sessionTenantName = profile?.tenant?.name
  const detailsContext = resolveTicketDetailsContext({
    ticket,
    isLawyer,
    tenantName,
    sessionCompanyName,
    sessionTenantName,
  })
  const currentStatus = statuses.find((s) => s.id === ticket.ticket_status_id)
  const currentImportance = importances.find((importance) => importance.id === ticket.ticket_importance_id)
    ?? ticket.ticket_importance

  return (
    <div className="flex flex-col gap-6 p-5">
      <div className="space-y-2">
        <SectionLabel>Estatus</SectionLabel>
        <TicketStatusField
          isLawyer={isLawyer}
          statuses={statuses}
          ticketStatusId={ticket.ticket_status_id}
          currentStatus={currentStatus}
          onStatusChange={onStatusChange}
        />
      </div>

      <div className="space-y-2">
        <SectionLabel>Prioridad</SectionLabel>
        <TicketImportanceField
          isLawyer={isLawyer}
          ticketImportanceId={ticket.ticket_importance_id}
          importances={importances}
          currentImportance={currentImportance}
          onImportanceChange={onImportanceChange}
        />
      </div>

      <div className="space-y-2">
        <SectionLabel>Asignado a</SectionLabel>
        <TicketAssignmentField
          assignedUser={assignedUser}
          isLawyer={isLawyer}
          onAssignMe={onAssignMe}
        />
      </div>

      <div className="h-px bg-border" />

      <div className="space-y-2">
        <SectionLabel>Detalles</SectionLabel>
        <TicketDetailsCard
          folio={ticket.folio}
          createdAt={ticket.created_at}
          context={detailsContext}
        />
      </div>

      <div className="h-px bg-border" />

      <div className="space-y-3 min-h-0">
        <SectionLabel>Actividad</SectionLabel>
        <div className="overflow-y-auto">
          <TicketActivityLog ticketId={ticket.id} />
        </div>
      </div>
    </div>
  )
}
