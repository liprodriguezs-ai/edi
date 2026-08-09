import { useEffect, useRef } from 'react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { useHistoryTicketList } from '@/modules/historyTicket/hooks/useHistoryTicketList'
import type { IHistoryTicket } from '@/modules/historyTicket/schema'
import { useHistoryTicketStore } from '@/modules/historyTicket/store/historyTicketStore'
import { useTicketDetailStore } from '@/modules/ticket/store/ticketDetailStore'
import { displayInitials } from '@/shared/utils/displayInitials'
import { formatDistanceToNow, parseISO } from 'date-fns'
import { es } from 'date-fns/locale'
import { Activity, FileUp, MessageSquare, Ticket, UserPlus } from 'lucide-react'

interface TicketActivityLogProps {
  ticketId: string
}

/** Mapa de colores del punto por tipo de acción */
const ACTION_DOT_COLORS: Record<string, string> = {
  created: 'bg-green-500',
  updated: 'bg-blue-500',
  deleted: 'bg-red-500',
}

/** Mapa de iconos por historyable_type (último segmento del namespace) */
const HISTORYABLE_ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
  Ticket: Ticket,
  TicketMessage: MessageSquare,
  TicketAssignment: UserPlus,
  DocumentTicket: FileUp,
}

function resolveIcon(historyableType?: string): React.ComponentType<{ className?: string }> {
  if (!historyableType) return Activity
  const model = historyableType.split('\\').pop() ?? ''
  return HISTORYABLE_ICONS[model] ?? Activity
}

function formatRelativeDate(dateStr?: string): string {
  if (!dateStr) return ''
  try {
    const date = parseISO(dateStr)
    if (Number.isNaN(date.getTime())) return ''
    return formatDistanceToNow(date, { addSuffix: true, locale: es })
  } catch {
    return ''
  }
}

export function TicketActivityLog({ ticketId }: TicketActivityLogProps) {
  const version = useTicketDetailStore((s) => s.version)

  const { getList } = useHistoryTicketList({
    overrideParams: {
      filters: [{ field: 'ticket_id', operator: '=', value: ticketId }],
    },
  })

  const getListRef = useRef(getList)
  getListRef.current = getList

  // Refetch when version changes (any ticket action happened) or ticketId changes
  useEffect(() => {
    void getListRef.current()
  }, [version, ticketId])

  const { entities, isLoading } = useHistoryTicketStore()
  const hasLoaded = useRef(false)
  if (entities.length > 0) hasLoaded.current = true

  if (isLoading && !hasLoaded.current) {
    return <ActivityLogSkeleton />
  }

  if (entities.length === 0) {
    return (
      <p className="text-sm text-muted-foreground py-2">
        Sin actividad registrada
      </p>
    )
  }

  const sorted = [...entities].sort(
    (a, b) => new Date(b.created_at ?? 0).getTime() - new Date(a.created_at ?? 0).getTime(),
  )

  return (
    <ol className="relative ml-2 border-l border-border">
      {sorted.map((item) => (
        <ActivityLogEntry key={item.id} item={item} />
      ))}
    </ol>
  )
}

function ActivityLogEntry({ item }: { item: IHistoryTicket }) {
  const Icon = resolveIcon(item.historyable_type)
  const dotColor = ACTION_DOT_COLORS[item.action] ?? 'bg-muted-foreground/40'
  const authorName = item.user?.full_name ?? item.user?.name ?? 'Sistema'
  const relativeDate = formatRelativeDate(item.created_at)

  return (
    <li className="mb-3 ml-4 last:mb-0">
      {/* Dot */}
      <div
        className={cn(
          'absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-background',
          dotColor,
        )}
      />

      {/* Content */}
      <div className="flex items-start gap-2">
        <Avatar className="h-6 w-6 shrink-0">
          {item.user?.profile_image_url && (
            <AvatarImage src={item.user.profile_image_url} alt={authorName} />
          )}
          <AvatarFallback className="bg-primary/10 text-primary text-[8px] font-medium">
            {displayInitials(authorName)}
          </AvatarFallback>
        </Avatar>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1">
            <Icon className="h-3 w-3 shrink-0 text-muted-foreground" />
            <p className="text-xs leading-snug">
              <span className="font-semibold text-foreground">{authorName}</span>
              {item.description && (
                <span className="text-muted-foreground"> {item.description}</span>
              )}
            </p>
          </div>

          {relativeDate && (
            <time className="text-[10px] text-muted-foreground/70">
              {relativeDate}
            </time>
          )}
        </div>
      </div>
    </li>
  )
}

function ActivityLogSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="flex items-center gap-2">
          <Skeleton className="h-6 w-6 rounded-full shrink-0" />
          <div className="space-y-1 flex-1">
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-2 w-16" />
          </div>
        </div>
      ))}
    </div>
  )
}
