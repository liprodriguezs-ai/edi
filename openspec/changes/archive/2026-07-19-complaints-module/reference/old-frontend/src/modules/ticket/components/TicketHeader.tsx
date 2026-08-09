import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import type { ITicket, ITicketStatus } from '@/modules/ticket/schema'
import {
  ArrowUpDown,
  Circle,
  Link,
  MoreVertical,
  Paperclip,
  UserPlus,
  X,
} from 'lucide-react'

interface TicketHeaderProps {
  ticket: ITicket
  statuses: ITicketStatus[]
  onClose: () => void
  onStatusChange?: (ticketId: string, statusId: string) => void
}

export function TicketHeader({ ticket, statuses, onClose, onStatusChange }: TicketHeaderProps) {
  const attachmentsCount = ticket.media?.length ?? 0
  const relatedCount = 0

  return (
    <div className="shrink-0 space-y-3 p-4">
      {/* Top row: title + close button */}
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold leading-tight text-foreground">
            {ticket.title}
          </h2>
          <p className="mt-0.5 text-xs text-muted-foreground">{ticket.folio}</p>
        </div>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onClose}
          aria-label="Cerrar panel"
        >
          <X className="size-4" />
        </Button>
      </div>

      {/* Action buttons row */}
      <div className="flex flex-wrap items-center gap-1.5">
        <Button variant="outline" size="sm">
          <Link className="size-3.5" />
          Relacionados
          {relatedCount > 0 && (
            <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-[10px]">
              {relatedCount}
            </Badge>
          )}
        </Button>

        <Button variant="outline" size="sm">
          <Paperclip className="size-3.5" />
          Adjuntos
          {attachmentsCount > 0 && (
            <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-[10px]">
              {attachmentsCount}
            </Badge>
          )}
        </Button>

        <Button variant="outline" size="sm" className="text-primary border-primary/30 hover:bg-primary/5">
          <UserPlus className="size-3.5" />
          Asignarme
        </Button>

        <Button variant="outline" size="sm">
          <ArrowUpDown className="size-3.5" />
          Prioridad
        </Button>

        {/* Acciones dropdown */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" size="sm">
              <MoreVertical className="size-3.5" />
              Acciones
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuItem>
              <Link className="size-4" />
              Asociar a otro ticket
            </DropdownMenuItem>

            {statuses.length > 0 && (
              <>
                <DropdownMenuSeparator />
                {statuses.map((status) => (
                  <DropdownMenuItem
                    key={status.id}
                    onClick={() => onStatusChange?.(ticket.id, status.id)}
                  >
                    <Circle
                      className="size-3 shrink-0"
                      style={{ color: status.color, fill: status.color }}
                    />
                    <span>{status.name}</span>
                  </DropdownMenuItem>
                ))}
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
