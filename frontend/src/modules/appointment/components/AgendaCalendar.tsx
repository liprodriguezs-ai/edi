import { childFullName } from '@/modules/child/schema'
import type { IAppointment } from '@/modules/appointment/schema'
import type { AgendaView } from '@/modules/appointment/utils/agendaView'
import { appointmentStatusKey, isActiveOnAgenda } from '@/modules/appointment/utils/appointmentStatus'
import { instantToTenantWallDate, tenantNowWallDate, wallClock } from '@/shared/utils/tenantTime'
import { format, getDay, parse, startOfWeek } from 'date-fns'
import { es } from 'date-fns/locale'
import { useCallback, useMemo } from 'react'
import { Calendar, dateFnsLocalizer, type Formats, type Messages, type SlotInfo } from 'react-big-calendar'
import withDragAndDropExport, { type EventInteractionArgs } from 'react-big-calendar/lib/addons/dragAndDrop'
import 'react-big-calendar/lib/addons/dragAndDrop/styles.css'
import 'react-big-calendar/lib/css/react-big-calendar.css'
import '@/modules/appointment/components/agenda.css'

/** Vistas que pinta el calendario; la lista la pinta `AgendaListView`. */
export type AgendaCalendarView = Exclude<AgendaView, 'list'>

export interface AgendaPractitioner {
  id: string
  name: string
}

interface AgendaEvent {
  id: string
  title: string
  start: Date
  end: Date
  resourceId: string
  appointment: IAppointment
}

interface AgendaResource {
  id: string
  title: string
}

interface AgendaCalendarProps {
  appointments: IAppointment[]
  practitioners: AgendaPractitioner[]
  timeZone: string
  view: AgendaCalendarView
  /** Fecha de pared del tenant que el calendario tiene a la vista. */
  date: Date
  canBook: boolean
  canMove: boolean
  /** Clic en un día del mes (o en su número): abre ese día. */
  onOpenDay: (_date: Date) => void
  /** Hueco libre elegido (fecha de pared) y, en la vista de día, el profesional de la columna. */
  onSelectSlot: (_start: Date, _practitionerId?: string) => void
  onSelectAppointment: (_appointment: IAppointment) => void
  /** Arrastre para reprogramar (fecha de pared destino). */
  onMoveAppointment: (_appointment: IAppointment, _start: Date, _practitionerId?: string) => void
}

const localizer = dateFnsLocalizer({
  format,
  parse,
  startOfWeek: (date: Date) => startOfWeek(date, { weekStartsOn: 1 }),
  getDay,
  locales: { es },
})

// The addon is CommonJS only: the production build hands over the function,
// Vite's dev server the module namespace ({ default }). Accept both.
const withDragAndDrop =
  typeof withDragAndDropExport === 'function'
    ? withDragAndDropExport
    : (withDragAndDropExport as unknown as { default: typeof withDragAndDropExport }).default

const DragAndDropCalendar = withDragAndDrop<AgendaEvent, AgendaResource>(Calendar)

const MESSAGES: Messages<AgendaEvent> = {
  today: 'Hoy',
  previous: 'Anterior',
  next: 'Siguiente',
  day: 'Día',
  week: 'Semana',
  month: 'Mes',
  agenda: 'Lista',
  date: 'Fecha',
  time: 'Hora',
  event: 'Cita',
  allDay: 'Todo el día',
  noEventsInRange: 'No hay citas en este rango.',
  showMore: (total: number) => `+${total} más`,
}

const FORMATS: Formats = {
  timeGutterFormat: 'HH:mm',
  eventTimeRangeFormat: ({ start, end }, culture, loc) =>
    `${loc?.format(start, 'HH:mm', culture) ?? ''} – ${loc?.format(end, 'HH:mm', culture) ?? ''}`,
  dayFormat: 'EEE d',
  weekdayFormat: 'EEE',
  dateFormat: 'd',
  dayHeaderFormat: "EEEE d 'de' MMMM",
  dayRangeHeaderFormat: ({ start, end }, culture, loc) =>
    `${loc?.format(start, "d 'de' MMM", culture) ?? ''} – ${loc?.format(end, "d 'de' MMM yyyy", culture) ?? ''}`,
}

const VISIBLE_START = wallClock(7)
const VISIBLE_END = wallClock(20)
const SCROLL_TO = wallClock(8)
const UNASSIGNED_RESOURCE = 'unassigned'

/**
 * Agenda del centro (S9). react-big-calendar trabaja en la zona del navegador,
 * así que todo entra y sale como "fecha de pared" del tenant (ver tenantTime.ts):
 * la cuadrícula siempre muestra la hora de la clínica aunque el navegador esté
 * en otra zona. En la vista de día cada profesional es una columna.
 */
export function AgendaCalendar({
  appointments,
  practitioners,
  timeZone,
  view,
  date,
  canBook,
  canMove,
  onOpenDay,
  onSelectSlot,
  onSelectAppointment,
  onMoveAppointment,
}: AgendaCalendarProps) {
  const events = useMemo<AgendaEvent[]>(
    () =>
      appointments.filter(isActiveOnAgenda).map((appointment) => ({
        id: appointment.id,
        title: appointment.child ? childFullName(appointment.child) : 'Niño sin nombre',
        start: instantToTenantWallDate(appointment.starts_at, timeZone),
        end: instantToTenantWallDate(appointment.ends_at, timeZone),
        resourceId: appointment.practitioner_id ?? UNASSIGNED_RESOURCE,
        appointment,
      })),
    [appointments, timeZone]
  )

  const resources = useMemo<AgendaResource[] | undefined>(() => {
    if (view !== 'day' || practitioners.length === 0) return undefined
    return practitioners.map((practitioner) => ({ id: practitioner.id, title: practitioner.name }))
  }, [practitioners, view])

  const getNow = useCallback(() => tenantNowWallDate(timeZone), [timeZone])

  const isMovable = useCallback(
    (event: AgendaEvent) => {
      const key = appointmentStatusKey(event.appointment)
      // Rescheduling needs a time slot: the month grid only shows days.
      if (view === 'month') return false
      return canMove && (key === 'scheduled' || key === 'confirmed') && event.start > tenantNowWallDate(timeZone)
    },
    [canMove, timeZone, view]
  )

  const handleSelectSlot = useCallback(
    (slot: SlotInfo) => {
      // In the month grid a click picks a whole day, not a time: open that day.
      if (view === 'month') {
        onOpenDay(slot.start)
        return
      }
      if (!canBook) return
      const practitionerId = typeof slot.resourceId === 'string' ? slot.resourceId : undefined
      onSelectSlot(slot.start, practitionerId)
    },
    [canBook, onOpenDay, onSelectSlot, view]
  )

  const handleEventDrop = useCallback(
    ({ event, start, resourceId }: EventInteractionArgs<AgendaEvent>) => {
      if (!(start instanceof Date)) return
      const practitionerId = typeof resourceId === 'string' ? resourceId : undefined
      onMoveAppointment(event.appointment, start, practitionerId)
    },
    [onMoveAppointment]
  )

  const eventPropGetter = useCallback(
    (event: AgendaEvent) => ({
      className: `agenda-event agenda-event--${appointmentStatusKey(event.appointment)}`,
    }),
    []
  )

  return (
    <div className="agenda-calendar">
      <DragAndDropCalendar
        localizer={localizer}
        culture="es"
        messages={MESSAGES}
        formats={FORMATS}
        events={events}
        resources={resources}
        resourceIdAccessor="id"
        resourceTitleAccessor="title"
        view={view}
        views={['day', 'week', 'month']}
        date={date}
        toolbar={false}
        onView={() => undefined}
        onNavigate={() => undefined}
        onDrillDown={(day) => onOpenDay(day)}
        getNow={getNow}
        min={VISIBLE_START}
        max={VISIBLE_END}
        scrollToTime={SCROLL_TO}
        step={15}
        timeslots={2}
        selectable={canBook || view === 'month'}
        onSelectSlot={handleSelectSlot}
        onSelectEvent={(event) => onSelectAppointment(event.appointment)}
        draggableAccessor={isMovable}
        resizable={false}
        onEventDrop={handleEventDrop}
        eventPropGetter={eventPropGetter}
        dayLayoutAlgorithm="no-overlap"
        popup
      />
    </div>
  )
}
