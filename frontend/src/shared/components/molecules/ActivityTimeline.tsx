import { Empty, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import type { TimelineEvent } from '@/shared/types/timeline'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { IconFolderCode } from '@tabler/icons-react'
import { ArrowRight, MessageSquare } from 'lucide-react'
import { Skeleton } from 'boneyard-js/react'

interface ActivityTimelineProps {
  events: TimelineEvent[]
  emptyMessage?: string
  className?: string
  isLoading?: boolean
}

function getDefaultIcon(type: TimelineEvent['type']): React.ReactNode {
  if (type === 'stage_move') {
    return <ArrowRight className="h-3 w-3 text-muted-foreground shrink-0" />
  }
  return <MessageSquare className="h-3 w-3 text-muted-foreground shrink-0" />
}

export function ActivityTimeline({
  events,
  emptyMessage = 'Sin actividad registrada',
  className,
  isLoading = false,
}: ActivityTimelineProps) {
  return (
    <Skeleton name="activity-timeline" loading={isLoading}>
      <ActivityTimelineContent
        events={events}
        emptyMessage={emptyMessage}
        className={className}
      />
    </Skeleton>
  )
}

interface ActivityTimelineContentProps {
  events: TimelineEvent[]
  emptyMessage: string
  className?: string
}

function ActivityTimelineContent({ events, emptyMessage, className }: ActivityTimelineContentProps) {
  if (events.length === 0) {
    return (
      <Empty className={className}>
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <IconFolderCode />
          </EmptyMedia>
          <EmptyTitle>{emptyMessage}</EmptyTitle>
        </EmptyHeader>
      </Empty>
    )
  }

  const sorted = [...events].sort(
    (a, b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime(),
  )

  return (
    <ol className={`relative ml-3 border-l border-border ${className ?? ''}`}>
      {sorted.map((event) => (
        <ActivityTimelineItem key={event.id} event={event} />
      ))}
    </ol>
  )
}

function ActivityTimelineItem({ event }: { event: TimelineEvent }) {
  const icon = event.icon ?? getDefaultIcon(event.type)

  return (
    <li className="mb-4 ml-4 last:mb-0">
      <div className="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border border-background bg-muted-foreground/40" />
      <div className="flex items-center gap-1.5">
        {icon}
        <p className="text-sm text-foreground">
          <span className="font-medium">{event.actor_name}</span>
          {' '}
          {event.title}
        </p>
      </div>
      {event.description && (
        <p className="mt-0.5 text-xs text-muted-foreground">{event.description}</p>
      )}
      <time className="text-xs text-muted-foreground">
        {displayFormatDate(event.occurred_at)}
      </time>
    </li>
  )
}
