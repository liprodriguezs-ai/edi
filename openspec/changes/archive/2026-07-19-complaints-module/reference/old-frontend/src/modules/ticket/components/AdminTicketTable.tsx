import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { AdminTicketFilters } from '@/modules/ticket/hooks/useAdminTickets'
import { getTicketCompanyName } from '@/modules/ticket/utils/ticketDisplay'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import { Pagination } from '@/shared/components/Pagination'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ExternalLink } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { ITicket } from '@/modules/ticket/schema'
import type { IPaginationMeta } from '@/shared/interfaces/Entity'

interface AdminTicketTableProps {
  tickets: ITicket[]
  meta: IPaginationMeta | null
  isLoading: boolean
  filters: AdminTicketFilters
  updateFilters: (_newFilters: Partial<AdminTicketFilters>) => void
}

interface AdminTicketRowProps {
  ticket: ITicket
  onTicketClick: (_ticket: ITicket) => void
}

function getTicketCreatorName(ticket: ITicket): string {
  if (!ticket.user) {
    return '—'
  }

  if (ticket.user.full_name && ticket.user.full_name.trim() !== '') {
    return ticket.user.full_name
  }

  const fullName = [ticket.user.name, ticket.user.last_name]
    .filter((value): value is string => Boolean(value && value.trim() !== ''))
    .join(' ')
    .trim()

  return fullName !== '' ? fullName : '—'
}

function AdminTicketRow({ ticket, onTicketClick }: AdminTicketRowProps) {
  const statusName = ticket.ticket_status?.name ?? '—'
  const statusColor = ticket.ticket_status?.color
  const importanceName = ticket.ticket_importance?.name
  const importanceColor = ticket.ticket_importance?.color
  const creatorName = getTicketCreatorName(ticket)
  const companyName = getTicketCompanyName(ticket) ?? '—'

  return (
    <TableRow
      key={ticket.id}
      className="cursor-pointer"
      onClick={() => onTicketClick(ticket)}
    >
      <TableCell>
        <Badge variant="secondary" className="text-xs font-medium">
          {companyName}
        </Badge>
      </TableCell>
      <TableCell className="font-mono text-primary font-semibold">
        {ticket.folio}
      </TableCell>
      <TableCell className="max-w-[200px] truncate">
        {ticket.title}
      </TableCell>
      <TableCell className="hidden md:table-cell">
        <span className="inline-flex items-center gap-1.5 text-sm">
          {statusColor && (
            <span
              className="size-2 rounded-full shrink-0"
              style={{ backgroundColor: statusColor }}
            />
          )}
          {statusName}
        </span>
      </TableCell>
      <TableCell className="hidden md:table-cell">
        {importanceName ? (
          <span className="inline-flex items-center gap-1.5 text-sm">
            {importanceColor && (
              <span
                className="size-2 rounded-full shrink-0"
                style={{ backgroundColor: importanceColor }}
              />
            )}
            {importanceName}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        )}
      </TableCell>
      <TableCell className="hidden lg:table-cell text-muted-foreground">
        {creatorName}
      </TableCell>
      <TableCell className="hidden lg:table-cell text-muted-foreground">
        {displayFormatDate(ticket.created_at)}
      </TableCell>
      <TableCell className="text-right">
        <ExternalLink className="size-4 text-muted-foreground" />
      </TableCell>
    </TableRow>
  )
}

export function AdminTicketTable({ tickets, meta, isLoading, filters, updateFilters }: AdminTicketTableProps) {
  const navigate = useNavigate()

  const handleTicketClick = (ticket: ITicket) => {
    useTenantContextStore.setState({
      tenantId: ticket.tenant_id ?? null,
      tenantName: getTicketCompanyName(ticket) ?? ticket.tenant_name ?? null,
    })
    navigate(`/ticket/${ticket.id}`)
  }

  const pagination = meta
    ? {
        page: meta.current_page,
        limit: meta.per_page,
        total: meta.total,
        pages: meta.last_page,
        per_page: meta.per_page,
      }
    : null

  return (
    <div className="space-y-6">
      {/* Tabla */}
      <section>
        {isLoading ? (
          <div>
            <TableLoadingState />
          </div>
        ) : tickets.length === 0 ? (
          <div>
            <TableEmptyState />
          </div>
        ) : (
          <>
            <div className="overflow-hidden rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Empresa</TableHead>
                    <TableHead>Folio</TableHead>
                    <TableHead>Titulo</TableHead>
                    <TableHead className="hidden md:table-cell">Estatus</TableHead>
                    <TableHead className="hidden md:table-cell">Prioridad</TableHead>
                    <TableHead className="hidden lg:table-cell">Creado por</TableHead>
                    <TableHead className="hidden lg:table-cell">Fecha</TableHead>
                    <TableHead className="text-right"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {tickets.map((ticket) => (
                    <AdminTicketRow
                      key={ticket.id}
                      ticket={ticket}
                      onTicketClick={handleTicketClick}
                    />
                  ))}
                </TableBody>
              </Table>
            </div>
            {tickets.length > 0 && pagination && (
              <Pagination
                pagination={pagination}
                onPageChanged={(page, size) =>
                  updateFilters({ page, per_page: size ?? filters.per_page })
                }
              />
            )}
          </>
        )}
      </section>
    </div>
  )
}
