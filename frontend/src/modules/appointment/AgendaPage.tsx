import { Button } from '@/components/ui/button'
import { AgendaCalendar, type AgendaPractitioner } from '@/modules/appointment/components/AgendaCalendar'
import { AgendaCenterSelect, ALL_CENTERS } from '@/modules/appointment/components/AgendaCenterSelect'
import { AgendaListView } from '@/modules/appointment/components/AgendaListView'
import { AgendaTodaySummary } from '@/modules/appointment/components/AgendaTodaySummary'
import { AgendaToolbar } from '@/modules/appointment/components/AgendaToolbar'
import { AppointmentDetailSheet } from '@/modules/appointment/components/AppointmentDetailSheet'
import {
  AppointmentFormSheet,
  type AppointmentFormMode,
  type AppointmentFormPrefill,
} from '@/modules/appointment/components/AppointmentFormSheet'
import { WaitingRoomPanel } from '@/modules/appointment/components/WaitingRoomPanel'
import {
  defaultAgendaCenter,
  readStoredAgendaCenter,
  storeAgendaCenter,
} from '@/modules/appointment/utils/defaultAgendaCenter'
import { sessionStore } from '@/shared/auth/sessionStore'
import { useAgendaAppointments, useAppointmentActions, useWaitingRoom } from '@/modules/appointment/hooks/useAgenda'
import type { IAppointment } from '@/modules/appointment/schema'
import { agendaRange, type AgendaView } from '@/modules/appointment/utils/agendaView'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { toast } from '@/shared/hooks/useToast'
import {
  civilDateToWallDate,
  isInstantAtOrAfter,
  tenantNowWallDate,
  tenantToday,
  tenantWallDateToUtc,
  wallDateToCivilDate,
  wallDateToDateTimeLocal,
} from '@/shared/utils/tenantTime'
import { CalendarPlus, DoorOpen, MoonStar } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useStore } from 'zustand'

type PractitionerItem = ICatalogItem & {
  user_id?: string | null
  health_center_id?: string | null
  user?: { name?: string } | null
}

interface FormState {
  mode: AppointmentFormMode
  prefill?: AppointmentFormPrefill
}

/**
 * Agenda del centro, pantalla de inicio del personal clínico
 * (odd/tasks/edi-agenda.md S5, S6, S8, S9; odd/tasks/edi-clinical-record.md S1, S2):
 * resumen de hoy, vistas Día (una columna por profesional), Semana, Mes y Lista,
 * y sala de espera. El flujo natural es agendar → "Llegó" → "Iniciar atención"
 * (crea la visita y abre el EDI).
 */
export function AgendaPage() {
  const timeZone = useTenantTimeZone()
  const [view, setView] = useState<AgendaView>('day')
  const [date, setDate] = useState<Date>(() => tenantNowWallDate(timeZone))
  // null = the user has not picked a center yet: derive one once practitioners load.
  const [chosenCenter, setChosenCenter] = useState<string | null>(readStoredAgendaCenter)
  const userId = useStore(sessionStore, (state) => state.profile?.user?.id)
  const [form, setForm] = useState<FormState | null>(null)
  const [selected, setSelected] = useState<IAppointment | null>(null)
  const [confirmCloseDay, setConfirmCloseDay] = useState(false)

  const permissions = useMultiplePermissions({
    canCreate: 'create.appointment',
    canUpdate: 'update.appointment',
    canCreateVisit: 'create.visit',
  })
  // "Iniciar atención" crea la visita: el servidor exige ambos permisos.
  const canStart = permissions.canUpdate && permissions.canCreateVisit

  const viewedDay = wallDateToCivilDate(date)
  const range = agendaRange(view, viewedDay)
  const today = tenantToday(timeZone)

  // Los profesionales SON las columnas de la cuadrícula (no un dropdown), así
  // que se cargan con la página: sin ellos no hay huecos donde agendar.
  const { catalogs: practitionerCatalog, isLoading: isLoadingPractitioners } = useCatalogs(
    useMemo(() => ({ [CatalogType.PRACTITIONER_PROFILE]: { _with: 'user' } }), [])
  )
  const practitionerItems = useMemo(
    () => (practitionerCatalog[CatalogType.PRACTITIONER_PROFILE] ?? []) as PractitionerItem[],
    [practitionerCatalog]
  )

  // S15: open on the user's own center (or the only one) until they pick another.
  const healthCenterId = chosenCenter ?? defaultAgendaCenter(practitionerItems, userId) ?? ALL_CENTERS
  const centerFilter = healthCenterId === ALL_CENTERS ? undefined : healthCenterId
  // Wait for the default center before querying, so the agenda is not fetched twice.
  const centerResolved = chosenCenter !== null || !isLoadingPractitioners

  const agenda = useAgendaAppointments(range.from, range.to, centerFilter, centerResolved)
  // Same query key as the day view of today, so looking at today costs one request.
  const todayAgenda = useAgendaAppointments(today, today, centerFilter, centerResolved)
  const waitingRoom = useWaitingRoom(centerFilter, centerResolved)
  const actions = useAppointmentActions()

  const practitioners = useMemo<AgendaPractitioner[]>(() => {
    return practitionerItems
      .filter((item) => !centerFilter || item.health_center_id === centerFilter)
      .map((item) => ({ id: item.id, name: item.user?.name || item.name || 'Profesional' }))
  }, [practitionerItems, centerFilter])

  const openBooking = useCallback((prefill?: AppointmentFormPrefill) => setForm({ mode: 'book', prefill }), [])

  const handleSelectSlot = useCallback(
    (start: Date, practitionerId?: string) => {
      const startsAt = tenantWallDateToUtc(start, timeZone)
      if (startsAt.ok && !isInstantAtOrAfter(startsAt.value)) {
        toast.error('Ese horario ya pasó', 'Elige un hueco a partir de ahora o usa “Registrar sin cita”.')
        return
      }
      openBooking({ startsAtLocal: wallDateToDateTimeLocal(start), practitionerId })
    },
    [openBooking, timeZone]
  )

  const handleMove = useCallback(
    (appointment: IAppointment, start: Date, practitionerId?: string) => {
      const startsAt = tenantWallDateToUtc(start, timeZone)
      if (!startsAt.ok || !isInstantAtOrAfter(startsAt.value)) {
        toast.error('No se puede mover a un horario que ya pasó')
        return
      }
      actions.reschedule.mutate({
        id: appointment.id,
        payload: {
          starts_at: startsAt.value,
          practitioner_id: practitionerId && practitionerId !== appointment.practitioner_id ? practitionerId : undefined,
        },
      })
    },
    [actions.reschedule, timeZone]
  )

  const closeDayAllowed = permissions.canUpdate && view === 'day' && viewedDay <= today

  const goToDay = useCallback((day: string) => setDate(civilDateToWallDate(day)), [])
  const openDay = useCallback((day: Date) => {
    setDate(day)
    setView('day')
  }, [])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Agenda"
        description="Lo que hay hoy, las citas por profesional y la sala de espera"
        actions={
          permissions.canCreate ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" onClick={() => setForm({ mode: 'walk_in' })}>
                <DoorOpen className="size-4" aria-hidden />
                Registrar sin cita
              </Button>
              <Button onClick={() => openBooking()}>
                <CalendarPlus className="size-4" aria-hidden />
                Agendar cita
              </Button>
            </div>
          ) : undefined
        }
      />

      <AgendaTodaySummary appointments={todayAgenda.data ?? []} isLoading={todayAgenda.isLoading} />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <AgendaCenterSelect
          value={healthCenterId}
          onChange={(value) => {
            setChosenCenter(value)
            storeAgendaCenter(value)
          }}
        />
        {closeDayAllowed && (
          <Button variant="outline" onClick={() => setConfirmCloseDay(true)}>
            <MoonStar className="size-4" aria-hidden />
            Cerrar día
          </Button>
        )}
      </div>

      <div className="grid grid-cols-12 gap-6">
        <div className="order-2 col-span-12 min-w-0 space-y-4 lg:order-1 lg:col-span-9">
          <AgendaToolbar view={view} day={viewedDay} today={today} onViewChange={setView} onDayChange={goToDay} />
          {agenda.isError ? (
            <EntityNotFound
              variant="error"
              title="No se pudo cargar la agenda"
              onRetry={() => void agenda.refetch()}
            />
          ) : view === 'list' ? (
            <AgendaListView
              appointments={agenda.data ?? []}
              timeZone={timeZone}
              today={today}
              canStart={canStart}
              startingId={actions.start.isPending ? (actions.start.variables ?? null) : null}
              onStart={(appointment) => actions.start.mutate(appointment.id)}
              onSelect={setSelected}
            />
          ) : (
            <AgendaCalendar
              appointments={agenda.data ?? []}
              practitioners={practitioners}
              timeZone={timeZone}
              view={view}
              date={date}
              canBook={permissions.canCreate}
              canMove={permissions.canUpdate}
              onOpenDay={openDay}
              onSelectSlot={handleSelectSlot}
              onSelectAppointment={setSelected}
              onMoveAppointment={handleMove}
            />
          )}
        </div>
        <aside className="order-1 col-span-12 lg:order-2 lg:col-span-3">
          <WaitingRoomPanel
            appointments={waitingRoom.data ?? []}
            isLoading={waitingRoom.isLoading}
            canStart={canStart}
            startingId={actions.start.isPending ? (actions.start.variables ?? null) : null}
            onStart={(appointment) => actions.start.mutate(appointment.id)}
            onSelect={setSelected}
          />
        </aside>
      </div>

      <AppointmentFormSheet
        isOpen={form !== null}
        mode={form?.mode ?? 'book'}
        prefill={form?.prefill}
        onClose={() => setForm(null)}
      />

      <AppointmentDetailSheet
        appointment={selected}
        actions={actions}
        canUpdate={permissions.canUpdate}
        canStart={canStart}
        onClose={() => setSelected(null)}
      />

      <ConfirmDialog
        isOpen={confirmCloseDay}
        title="¿Cerrar el día?"
        description="Las citas de este día que no llegaron y cuya hora ya pasó se marcarán como “No asistió”. Los niños en sala de espera no cambian."
        confirmLabel="Cerrar día"
        isLoading={actions.closeDay.isPending}
        onConfirm={() =>
          actions.closeDay.mutate(
            { date: viewedDay, healthCenterId: centerFilter },
            { onSettled: () => setConfirmCloseDay(false) }
          )
        }
        onCancel={() => setConfirmCloseDay(false)}
      />
    </div>
  )
}
