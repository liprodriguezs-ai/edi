import { Button } from '@/components/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import type { IComplaintHistoryChange, IComplaintHistoryEntry } from '@/modules/complaint/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, FilePlus2, Pencil, RotateCcw, Trash2 } from 'lucide-react'
import { Skeleton } from 'boneyard-js/react'
import { useState } from 'react'
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'

interface Props {
  complaintId: string
}

const EVENT_ICON: Record<IComplaintHistoryEntry['event'], typeof FilePlus2> = {
  created: FilePlus2,
  updated: Pencil,
  restored: RotateCcw,
  deleted: Trash2,
}

const EVENT_MILESTONE_LABEL: Record<IComplaintHistoryEntry['event'], string> = {
  created: 'Incidencia registrada',
  updated: 'Se actualizó la incidencia',
  restored: 'Incidencia restaurada',
  deleted: 'Incidencia eliminada',
}

function ChangeRow({ change }: { change: IComplaintHistoryChange }) {
  return (
    <div className='flex flex-wrap items-center gap-1.5 text-[12.5px]'>
      <span className='font-medium text-foreground'>{change.label}:</span>
      <span className='rounded-full bg-[var(--surface-2)] px-2 py-0.5 text-muted-foreground'>
        {change.from ?? '—'}
      </span>
      <span className='text-muted-foreground' aria-hidden>
        →
      </span>
      <span className='rounded-full bg-[var(--surface-2)] px-2 py-0.5 font-medium text-foreground'>
        {change.to ?? '—'}
      </span>
    </div>
  )
}

function HistoryTimelineEntry({
  entry,
  isLast,
}: {
  entry: IComplaintHistoryEntry
  isLast: boolean
}) {
  const Icon = EVENT_ICON[entry.event] ?? Pencil

  return (
    <li className='relative flex gap-3 pb-5'>
      {!isLast && (
        <span className='absolute left-[13px] top-6 h-full w-px bg-[var(--line-2)]' aria-hidden />
      )}
      <span className='flex size-7 flex-none items-center justify-center rounded-full border border-border bg-card text-muted-foreground'>
        <Icon className='size-3.5' />
      </span>
      <div className='min-w-0 flex-1 space-y-1.5 pt-0.5'>
        <div className='flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5'>
          <span className='text-[13px] font-medium text-foreground'>
            {entry.event === 'created' || entry.changes.length === 0
              ? EVENT_MILESTONE_LABEL[entry.event]
              : 'Cambios en la incidencia'}
          </span>
          <span className='text-[11.5px] text-muted-foreground'>
            {entry.user_name} · {displayFormatDate(entry.created_at)}
          </span>
        </div>
        {entry.changes.length > 0 && (
          <div className='space-y-1'>
            {entry.changes.map((change) => (
              <ChangeRow key={change.field} change={change} />
            ))}
          </div>
        )}
      </div>
    </li>
  )
}

/**
 * "Historial de cambios" (spec "History"): timeline vertical leído del
 * trail de auditoría ya existente, GET /complaint/{id}/history.
 *
 * INFRA-COST: colapsada por default; el fetch (React Query, `enabled` atado
 * al estado de apertura) dispara SOLO la primera vez que se expande — nunca
 * suma una request al montar el detalle. `staleTime` generoso porque el
 * historial de una incidencia ya vista rara vez necesita refrescarse en la
 * misma sesión.
 */
export function ComplaintHistorySection({ complaintId }: Props) {
  const [isOpen, setIsOpen] = useState(false)

  const {
    data: entries,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ['complaint-history', complaintId],
    queryFn: () => ComplaintResource.history(complaintId),
    enabled: isOpen,
    staleTime: 5 * 60 * 1000,
  })

  return (
    <Collapsible
      open={isOpen}
      onOpenChange={setIsOpen}
      className='rounded-[12px] border border-border bg-card p-5 shadow-sm'
    >
      <CollapsibleTrigger asChild>
        <Button
          variant='ghost'
          className='flex h-auto w-full items-center justify-between p-0 text-[13px] font-semibold uppercase tracking-wide text-muted-foreground hover:bg-transparent'
        >
          Historial de cambios
          <span className='flex items-center gap-1 text-[12px] font-normal normal-case text-muted-foreground'>
            {isOpen ? 'Ocultar historial' : 'Ver historial'}
            <ChevronDown
              className={`size-3.5 transition-transform ${isOpen ? 'rotate-180' : ''}`}
            />
          </span>
        </Button>
      </CollapsibleTrigger>

      <CollapsibleContent className='pt-4'>
        <Skeleton name='complaint-history' loading={isLoading}>
          {isError ? (
            <p className='text-[13px] text-destructive'>No se pudo cargar el historial.</p>
          ) : entries && entries.length > 0 ? (
            <ol className='space-y-0'>
              {entries.map((entry, index) => (
                <HistoryTimelineEntry
                  key={entry.id}
                  entry={entry}
                  isLast={index === entries.length - 1}
                />
              ))}
            </ol>
          ) : (
            <p className='text-[13px] text-muted-foreground'>Sin cambios registrados todavía.</p>
          )}
        </Skeleton>
      </CollapsibleContent>
    </Collapsible>
  )
}
