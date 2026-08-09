import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { TicketActivityLog } from '@/modules/ticket/components/TicketActivityLog'
import { TicketContactPanel } from '@/modules/ticket/components/TicketContactPanel'
import { TicketProperties } from '@/modules/ticket/components/TicketProperties'
import { TicketTimeline } from '@/modules/ticket/components/TicketTimeline'
import { useTicketDetail } from '@/modules/ticket/hooks/useTicketDetail'
import { useTicketImportance } from '@/modules/ticket/hooks/useTicketImportance'
import { useTicketStatusCatalog } from '@/modules/ticket/hooks/useTicketStatusCatalog'
import { TicketAssignmentResource } from '@/modules/ticket/services/TicketAssignmentResource'
import { TicketResource, moveTicketStatus } from '@/modules/ticket/services/TicketResource'
import { useTicketDetailStore } from '@/modules/ticket/store/ticketDetailStore'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import { useTicketStatusStore } from '@/modules/ticket/store/ticketStatusStore'
import { useRoles } from '@/shared/hooks/useRoles'
import { useSession } from '@/shared/auth/useSession'
import { toast } from '@/shared/hooks/useToast'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useLayoutContext } from '@/app/layout/useLayoutContext'
import { Activity, ArrowLeft, ChevronRight, MoreVertical, Settings, Ticket, User } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

type MobileSheetType = 'properties' | 'contact' | 'activity' | null

export const TicketDetailPage = () => {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { user } = useSession()
  const { hasRole } = useRoles()
  const isLawyer = hasRole(ERoleUserSlug.LAWYER)
  const { setFullWidth } = useLayoutContext()

  const clearTenant = useTenantContextStore((s) => s.clearTenant)

  const navigateBack = useCallback(() => {
    clearTenant()
    navigate('/ticket')
  }, [clearTenant, navigate])

  useEffect(() => {
    setFullWidth(true)
    return () => setFullWidth(false)
  }, [setFullWidth])

  const { ticket, isLoading, refetch, updateTicketLocal } = useTicketDetail(id ?? null)

  // Ensure statuses are loaded
  useTicketStatusCatalog()
  useTicketImportance()
  const statuses = useTicketStatusStore((s) => s.entities)

  const [showContact, setShowContact] = useState(false)
  const [mobileSheet, setMobileSheet] = useState<MobileSheetType>(null)
  const bump = useTicketDetailStore((s) => s.bump)

  const toggleContactPanel = useCallback(() => {
    setShowContact((prev) => !prev)
  }, [])

  const handleStatusChange = useCallback(
    async (statusId: string) => {
      if (!ticket) return
      try {
        await moveTicketStatus(ticket.id, statusId)
        updateTicketLocal({ ticket_status_id: statusId })
        bump()
        toast.success('Estado actualizado')
      } catch {
        toast.error('Error al actualizar el estado')
      }
    },
    [ticket, updateTicketLocal, bump],
  )

  const handleImportanceChange = useCallback(
    async (importanceId: string ) => {
      if (!ticket || !importanceId) return
      try {
        await TicketResource.update(ticket.id, {
          title: ticket.title,
          description: ticket.description,
          ticket_importance_id: importanceId
        })
        updateTicketLocal({ ticket_importance_id: importanceId })
        bump()
        toast.success('Prioridad actualizada')
      } catch {
        toast.error('Error al actualizar la prioridad')
      }
    },
    [ticket, updateTicketLocal, bump],
  )

  const handleAssignMe = useCallback(async () => {
    if (!ticket || !user) return
    try {
      await TicketAssignmentResource.create({
        ticket_id: ticket.id,
        user_id: user.id,
      })
      toast.success('Te has asignado al ticket')
      bump()
      await refetch()
    } catch {
      toast.error('Error al asignarte al ticket')
    }
  }, [ticket, user, refetch, bump])

  // Loading state
  if (isLoading) {
    return <DetailSkeleton navigate={navigate} />
  }

  // Not found
  if (!ticket) {
    return (
      <div className="flex h-[calc(100dvh-var(--header-height))] md:h-[calc(100dvh-var(--header-height)-1.5rem)] flex-col items-center justify-center gap-3">
        <Ticket className="size-12 text-muted-foreground/40" />
        <p className="text-lg font-medium text-muted-foreground">Ticket no encontrado</p>
        <Button variant="outline" onClick={() => navigateBack()}>
          Volver a tickets
        </Button>
      </div>
    )
  }

  const currentStatus = statuses.find((s) => s.id === ticket.ticket_status_id)

  return (
    <div className="flex flex-col h-[calc(100dvh-var(--header-height))] md:h-[calc(100dvh-var(--header-height)-1.5rem)] overflow-hidden -m-4 lg:-m-6">

      {/* === MOBILE HEADER (<lg) === */}
      <header className="lg:hidden shrink-0 flex items-center justify-between border-b bg-background px-3 py-2.5">
        <div className="flex items-center gap-2 min-w-0">
          <button
            type="button"
            onClick={() => navigateBack()}
            className="shrink-0 p-1.5 -ml-1 rounded-md hover:bg-muted transition-colors"
          >
            <ArrowLeft className="size-5" />
          </button>
          <div className="min-w-0">
            <div className="flex items-center gap-1.5">
              <span className="text-sm font-mono font-semibold text-primary truncate">
                {ticket.folio}
              </span>
              {currentStatus && (
                <span className="inline-flex items-center gap-1 rounded-full bg-muted/50 px-2 py-0.5 text-[10px] font-medium text-muted-foreground shrink-0">
                  <span
                    className="size-1.5 rounded-full"
                    style={{ backgroundColor: currentStatus.color }}
                  />
                  {currentStatus.name}
                </span>
              )}
            </div>
            <p className="text-xs text-muted-foreground truncate max-w-[200px]">
              {ticket.title}
            </p>
          </div>
        </div>

        {/* Three-dot menu */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className="p-2 rounded-md hover:bg-muted transition-colors"
            >
              <MoreVertical className="size-5" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => setMobileSheet('properties')}>
              <Settings className="size-4 mr-2" />
              Propiedades
            </DropdownMenuItem>
            {showContact && (
              <DropdownMenuItem onClick={() => setMobileSheet('contact')}>
                <User className="size-4 mr-2" />
                Contacto
              </DropdownMenuItem>
            )}
            <DropdownMenuItem onClick={() => setMobileSheet('activity')}>
              <Activity className="size-4 mr-2" />
              Actividad
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </header>

      {/* === DESKTOP HEADER (lg+) === */}
      <header className="hidden lg:flex shrink-0 items-center justify-between bg-background border-b px-6 py-3">
        {/* Left: Breadcrumb + status */}
        <div className="flex items-center gap-2 text-sm min-w-0">
          <button
            type="button"
            onClick={() => navigateBack()}
            className="shrink-0 text-muted-foreground hover:text-foreground hover:underline underline-offset-4 transition-colors font-medium"
          >
            Tickets
          </button>
          <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
          <span className="shrink-0 font-mono font-semibold text-primary">{ticket.folio}</span>
          <span className="shrink-0 text-muted-foreground/50">&mdash;</span>
          <span className="truncate max-w-[120px] sm:max-w-[300px] text-foreground/80">{ticket.title}</span>

          {/* Status indicator */}
          {currentStatus && (
            <span className="ml-2 inline-flex items-center gap-1.5 rounded-full bg-muted/50 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
              <span
                className="size-2 rounded-full shrink-0"
                style={{ backgroundColor: currentStatus.color }}
              />
              {currentStatus.name}
            </span>
          )}
        </div>

        {showContact && (
        <div className="flex items-center gap-2 shrink-0">
          <Button
            variant="ghost"
            size="sm"
            onClick={toggleContactPanel}
            className={cn(
              'transition-colors',
              showContact && 'bg-accent border border-border',
            )}
          >
            <User className="h-4 w-4 mr-1" />
            Contacto
          </Button>
        </div>
        )}
      </header>

      {/* === CONTENT: Timeline compartida mobile/desktop === */}
      <div className="flex-1 min-h-0 flex flex-col lg:flex-row lg:overflow-hidden">
        {/* Timeline — toma todo el ancho en mobile, porcentaje en desktop */}
        <div
          className={cn(
            'min-w-0 min-h-0 flex flex-col flex-1 lg:flex-none transition-all duration-300 ease-in-out',
            showContact ? 'lg:w-[50%]' : 'lg:w-[65%]',
          )}
        >
          <TicketTimeline ticket={ticket} />
        </div>

        {/* Properties sidebar — solo desktop */}
        <div
          className={cn(
            'hidden lg:block',
            'shrink-0 border-l overflow-y-auto transition-all duration-300 ease-in-out',
            showContact ? 'w-[25%]' : 'w-[35%]',
          )}
        >
          <TicketProperties
            ticket={ticket}
            statuses={statuses}
            onStatusChange={(statusId) => void handleStatusChange(statusId)}
            onImportanceChange={(importanceId) => void handleImportanceChange(importanceId)}
            onAssignMe={() => void handleAssignMe()}
            isLawyer={isLawyer}
          />
        </div>

        {/* Contact panel — solo desktop */}
        <div
          className={cn(
            'hidden lg:block shrink-0 border-l overflow-y-auto transition-all duration-300 ease-in-out',
            showContact ? 'w-[25%]' : 'w-0 border-l-0',
          )}
        >
          {showContact && <TicketContactPanel client={ticket.client} />}
        </div>
      </div>

      {/* === MOBILE SHEETS (bottom drawers) === */}
      <Sheet
        open={mobileSheet === 'properties'}
        onOpenChange={(open) => { if (!open) setMobileSheet(null) }}
      >
        <SheetContent side="bottom" className="h-[70vh] rounded-t-xl">
          <SheetHeader>
            <SheetTitle>Propiedades del ticket</SheetTitle>
          </SheetHeader>
          <div className="overflow-y-auto flex-1 min-h-0">
            <TicketProperties
              ticket={ticket}
              statuses={statuses}
              onStatusChange={(statusId) => void handleStatusChange(statusId)}
              onImportanceChange={(importanceId) => void handleImportanceChange(importanceId)}
              onAssignMe={() => void handleAssignMe()}
              isLawyer={isLawyer}
            />
          </div>
        </SheetContent>
      </Sheet>

      <Sheet
        open={mobileSheet === 'contact'}
        onOpenChange={(open) => { if (!open) setMobileSheet(null) }}
      >
        <SheetContent side="bottom" className="h-[50vh] rounded-t-xl">
          <SheetHeader>
            <SheetTitle>Informaci&oacute;n del contacto</SheetTitle>
          </SheetHeader>
          <div className="overflow-y-auto flex-1 min-h-0">
            <TicketContactPanel client={ticket.client} />
          </div>
        </SheetContent>
      </Sheet>

      <Sheet
        open={mobileSheet === 'activity'}
        onOpenChange={(open) => { if (!open) setMobileSheet(null) }}
      >
        <SheetContent side="bottom" className="h-[70vh] rounded-t-xl">
          <SheetHeader>
            <SheetTitle>Actividad del ticket</SheetTitle>
          </SheetHeader>
          <div className="overflow-y-auto flex-1 min-h-0 px-4 pb-4">
            <TicketActivityLog ticketId={ticket.id} />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}

// --- Skeleton for loading state ---

function DetailSkeleton({ navigate }: { navigate: (path: string) => void }) {
  void navigate
  return (
    <div className="flex flex-col h-[calc(100dvh-var(--header-height))] md:h-[calc(100dvh-var(--header-height)-1.5rem)] overflow-hidden -m-4 lg:-m-6">

      {/* === MOBILE HEADER SKELETON === */}
      <div className="lg:hidden shrink-0 flex items-center gap-2 border-b px-3 py-2.5">
        <Skeleton className="size-8 rounded-md" />
        <div className="space-y-1.5 flex-1">
          <div className="flex items-center gap-1.5">
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-4 w-14 rounded-full" />
          </div>
          <Skeleton className="h-3 w-36" />
        </div>
        <Skeleton className="size-8 rounded-md" />
      </div>

      {/* === DESKTOP HEADER SKELETON === */}
      <div className="hidden lg:flex shrink-0 items-center justify-between border-b px-6 py-3">
        <div className="flex items-center gap-2">
          <Skeleton className="h-4 w-16" />
          <Skeleton className="h-4 w-4" />
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-4 w-48" />
        </div>
        <Skeleton className="h-8 w-24" />
      </div>

      {/* === MOBILE BODY SKELETON: Chat bubbles === */}
      <div className="flex-1 min-h-0 lg:hidden p-3 space-y-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className={cn('flex gap-2', i % 2 === 1 ? 'justify-end' : 'justify-start')}>
            <div className={cn('flex gap-2 max-w-[80%]', i % 2 === 1 && 'flex-row-reverse')}>
              <Skeleton className="size-7 rounded-full shrink-0" />
              <div className="space-y-1.5">
                <Skeleton className="h-3 w-24" />
                <Skeleton className={cn('h-12 rounded-2xl', i % 2 === 0 ? 'w-48' : 'w-32')} />
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* === DESKTOP BODY SKELETON === */}
      <div className="hidden lg:flex flex-1 min-h-0">
        {/* Timeline skeleton */}
        <div className="flex-none w-[65%] p-6 space-y-6">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="flex gap-3">
              <Skeleton className="h-8 w-8 shrink-0 rounded-full" />
              <div className="flex-1 space-y-2">
                <Skeleton className="h-4 w-48" />
                <Skeleton className="h-16 w-full rounded-lg" />
              </div>
            </div>
          ))}
        </div>

        {/* Properties skeleton */}
        <div className="w-[35%] border-l p-4 space-y-6">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="space-y-2">
              <Skeleton className="h-3 w-20" />
              <Skeleton className="h-9 w-full" />
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
