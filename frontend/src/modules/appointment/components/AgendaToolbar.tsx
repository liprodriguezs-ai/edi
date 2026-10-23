import { Button } from '@/components/ui/button'
import { agendaHeading, shiftAgendaDay, type AgendaView } from '@/modules/appointment/utils/agendaView'
import { SegmentedControl } from '@/shared/components/molecules/SegmentedControl'
import { ChevronLeft, ChevronRight } from 'lucide-react'

const VIEW_OPTIONS: ReadonlyArray<{ value: AgendaView; label: string }> = [
  { value: 'day', label: 'Día' },
  { value: 'week', label: 'Semana' },
  { value: 'month', label: 'Mes' },
  { value: 'list', label: 'Lista' },
]

interface AgendaToolbarProps {
  view: AgendaView
  /** Día civil a la vista. */
  day: string
  /** Día civil de hoy en la zona del tenant. */
  today: string
  onViewChange: (_view: AgendaView) => void
  onDayChange: (_day: string) => void
}

/** Hoy, anterior/siguiente, el título de lo que se ve y el selector de vista. */
export function AgendaToolbar({ view, day, today, onViewChange, onDayChange }: AgendaToolbarProps) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center border border-border bg-card">
          <Button
            variant="ghost"
            size="icon"
            aria-label="Anterior"
            onClick={() => onDayChange(shiftAgendaDay(view, day, -1))}
          >
            <ChevronLeft className="size-4" aria-hidden />
          </Button>
          <Button
            variant="ghost"
            className="border-x border-border font-semibold"
            disabled={day === today}
            onClick={() => onDayChange(today)}
          >
            Hoy
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Siguiente"
            onClick={() => onDayChange(shiftAgendaDay(view, day, 1))}
          >
            <ChevronRight className="size-4" aria-hidden />
          </Button>
        </div>
        <h2 className="text-lg font-semibold first-letter:uppercase" aria-live="polite">
          {agendaHeading(view, day)}
        </h2>
      </div>
      <SegmentedControl
        aria-label="Vista de la agenda"
        value={view}
        onValueChange={onViewChange}
        options={VIEW_OPTIONS}
        className="w-auto"
      />
    </div>
  )
}
