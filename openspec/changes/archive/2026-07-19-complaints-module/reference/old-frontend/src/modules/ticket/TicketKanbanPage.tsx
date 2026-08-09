import { useLayoutContext } from '@/app/layout/useLayoutContext'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { AdminKanbanBoard } from '@/modules/ticket/components/AdminKanbanBoard'
import { TicketDetailPanel } from '@/modules/ticket/components/TicketDetailPanel'
import { TicketFilters } from '@/modules/ticket/components/TicketFilters'
import { TicketKanbanBoard } from '@/modules/ticket/components/TicketKanbanBoard'
import { TicketFormModal } from '@/modules/ticket/TicketFormModal'
import { useAdminTickets } from '@/modules/ticket/hooks/useAdminTickets'
import { getTicketCompanyName } from '@/modules/ticket/utils/ticketDisplay'
import { cn } from '@/lib/utils'
import type { ITicket } from '@/modules/ticket/schema'
import { useRoles } from '@/shared/hooks/useRoles'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { CrudMode } from '@/shared/enums/CrudMode'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import { Search } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

export const TicketKanbanPage = () => {
  const { hasRole } = useRoles()
  const isLawyer = hasRole(ERoleUserSlug.LAWYER)

  if (isLawyer) {
    return <LawyerTicketKanbanPage />
  }

  return <TicketKanbanPageContent />
}

/** Vista abogado: kanban cross-tenant con filtros y paginación. */
const LawyerTicketKanbanPage = () => {
  const { setFullWidth } = useLayoutContext()
  const { tickets, isLoading, isLoadingMore, hasMore, filters, updateFilters, loadMore, refetch } = useAdminTickets()
  const [searchInput, setSearchInput] = useState(filters.search ?? '')
  const [selectedTicket, setSelectedTicket] = useState<ITicket | null>(null)
  const { setTenant } = useTenantContextStore()

  useEffect(() => {
    setFullWidth(true)
    return () => setFullWidth(false)
  }, [setFullWidth])

  const handleSearchKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      updateFilters({ search: searchInput || undefined })
    }
  }

  const handleSearchBlur = () => {
    if (searchInput !== (filters.search ?? '')) {
      updateFilters({ search: searchInput || undefined })
    }
  }

  const uniqueTenants = useMemo(() => {
    return [
      ...new Map(
        tickets.map((ticket) => [
          ticket.tenant_id,
          { id: ticket.tenant_id, name: getTicketCompanyName(ticket) ?? ticket.tenant_name ?? '—' },
        ]),
      ).values(),
    ]
  }, [tickets])

  const handleTicketSelect = useCallback((ticket: ITicket) => {
    if (ticket.tenant_id) {
      setTenant(ticket.tenant_id, getTicketCompanyName(ticket) ?? ticket.tenant_name ?? '')
    }
    setSelectedTicket(ticket)
  }, [setTenant])

  const handleCloseDetail = useCallback(() => {
    setSelectedTicket(null)
  }, [])

  return (
    <div className="flex flex-col gap-6 h-[calc(100dvh-var(--header-height))] md:h-[calc(100dvh-var(--header-height)-1.5rem)] overflow-hidden -m-4 lg:-m-6 p-4 lg:p-6">
      <PageHeader
        title="Atención de dudas"
        description="Tickets de todas las empresas. Abre un folio para responder o marcar como atendida."
      />

      <div className="flex flex-wrap items-end gap-3">
        <div className="relative flex-1 min-w-[200px] max-w-md">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Buscar por folio o asunto..."
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            onKeyDown={handleSearchKeyDown}
            onBlur={handleSearchBlur}
            className="pl-9"
          />
        </div>

        <Select
          value={filters.tenant_id ?? 'all'}
          onValueChange={(value) =>
            updateFilters({ tenant_id: value === 'all' ? undefined : value })
          }
        >
          <SelectTrigger className="w-full sm:w-[220px]">
            <SelectValue placeholder="Empresa" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Todas las empresas</SelectItem>
            {uniqueTenants.map((tenant) => (
              <SelectItem key={tenant.id} value={tenant.id ?? ''}>
                {tenant.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex-1 min-h-0 flex">
        <div
          className={cn(
            'flex-1 min-w-0 transition-all duration-300',
            selectedTicket ? 'hidden lg:block lg:w-2/5' : 'w-full',
          )}
        >
          <AdminKanbanBoard
            tickets={tickets}
            isLoading={isLoading}
            hasMore={hasMore}
            isLoadingMore={isLoadingMore}
            onLoadMore={loadMore}
            onTicketsChanged={refetch}
            onTicketSelect={handleTicketSelect}
            selectedTicketId={selectedTicket?.id}
          />
        </div>

        {selectedTicket && (
          <div className="w-full lg:w-3/5 min-w-0 shrink-0">
            <TicketDetailPanel ticket={selectedTicket} onClose={handleCloseDetail} />
          </div>
        )}
      </div>
    </div>
  )
}

const TicketKanbanPageContent = () => {
  const { setFullWidth } = useLayoutContext()
  const navigate = useNavigate()

  const [selectedTicket, setSelectedTicket] = useState<ITicket | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [editingTicket, setEditingTicket] = useState<ITicket | null>(null)

  useEffect(() => {
    setFullWidth(true)
    return () => setFullWidth(false)
  }, [setFullWidth])

  const handleCreate = useCallback(() => {
    setEditingTicket(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleTicketSelect = useCallback((ticket: ITicket) => {
    setSelectedTicket(ticket)
  }, [])

  const handleCloseDetail = useCallback(() => {
    setSelectedTicket(null)
  }, [])

  return (
    <div className="flex flex-col gap-4 h-[calc(100dvh-var(--header-height))] md:h-[calc(100dvh-var(--header-height)-1.5rem)] overflow-hidden -m-4 lg:-m-6 p-4 lg:p-6">
      <TicketFilters
        onCreateClick={handleCreate}
        searchTerm={searchTerm}
        onSearchTermChange={setSearchTerm}
      />

      <div className="flex-1 min-h-0 flex">
        <div
          className={cn(
            'flex-1 min-w-0 transition-all duration-300',
            selectedTicket ? 'hidden lg:block lg:w-2/5' : 'w-full',
          )}
        >
          <TicketKanbanBoard
            onTicketSelect={handleTicketSelect}
            selectedTicketId={selectedTicket?.id}
            searchTerm={searchTerm}
          />
        </div>

        {selectedTicket && (
          <div className="w-full lg:w-3/5 min-w-0 shrink-0">
            <TicketDetailPanel ticket={selectedTicket} onClose={handleCloseDetail} />
          </div>
        )}
      </div>

      <TicketFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={editingTicket}
        onClose={() => { setModalOpen(false); setEditingTicket(null) }}
        onSuccess={(createdTicket) => {
          setModalOpen(false)
          setEditingTicket(null)

          if (createdTicket?.id) {
            navigate(`/ticket/${createdTicket.id}`)
          }
        }}
      />
    </div>
  )
}
