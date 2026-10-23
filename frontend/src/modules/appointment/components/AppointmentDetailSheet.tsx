import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import type { IAppointment } from '@/modules/appointment/schema'
import type { useAppointmentActions } from '@/modules/appointment/hooks/useAgenda'
import {
  appointmentStatusKey,
  appointmentStatusTone,
  availableActions,
} from '@/modules/appointment/utils/appointmentStatus'
import { childFullName } from '@/modules/child/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Modal } from '@/shared/components/Modal'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import {
  formatDateOnly,
  formatTenantTime,
  instantToTenantCivilDate,
  isInstantAtOrAfter,
  tenantDateTimeLocalToUtc,
  tenantToday,
  utcToTenantDateTimeLocal,
} from '@/shared/utils/tenantTime'
import { CalendarClock, CircleCheck, DoorOpen, Play, UserX, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

type AppointmentActions = ReturnType<typeof useAppointmentActions>

interface AppointmentDetailSheetProps {
  appointment: IAppointment | null
  actions: AppointmentActions
  canUpdate: boolean
  canStart: boolean
  onClose: () => void
}

type Panel = 'none' | 'cancel' | 'reschedule'

/** Detalle de una cita con las transiciones que el servidor aceptará en su estado actual. */
export function AppointmentDetailSheet(props: AppointmentDetailSheetProps) {
  if (!props.appointment) return null
  return <AppointmentDetailBody {...props} appointment={props.appointment} />
}

function AppointmentDetailBody({
  appointment,
  actions,
  canUpdate,
  canStart,
  onClose,
}: AppointmentDetailSheetProps & { appointment: IAppointment }) {
  const timeZone = useTenantTimeZone()
  const [panel, setPanel] = useState<Panel>('none')
  const [reason, setReason] = useState('')
  const [newStart, setNewStart] = useState(() => utcToTenantDateTimeLocal(appointment.starts_at, timeZone))
  const [panelError, setPanelError] = useState<string | null>(null)

  const statusKey = appointmentStatusKey(appointment)
  const day = instantToTenantCivilDate(appointment.starts_at, timeZone)
  const isToday = day === tenantToday(timeZone)
  const hasStarted = !isInstantAtOrAfter(appointment.starts_at)
  const allowed = availableActions(appointment, isToday, hasStarted)
  const childName = appointment.child ? childFullName(appointment.child) : 'Niño sin nombre'
  const practitionerName = appointment.practitioner?.user?.name ?? 'Sin profesional'

  const busy =
    actions.confirm.isPending ||
    actions.arrive.isPending ||
    actions.start.isPending ||
    actions.noShow.isPending ||
    actions.cancel.isPending ||
    actions.reschedule.isPending

  const closeAfter = (promise: Promise<unknown>) => {
    promise.then(onClose).catch(() => undefined)
  }

  const submitCancel = () => {
    if (reason.trim() === '') {
      setPanelError('Indica el motivo de la cancelación.')
      return
    }
    closeAfter(actions.cancel.mutateAsync({ id: appointment.id, reason: reason.trim() }))
  }

  const submitReschedule = () => {
    const startsAt = tenantDateTimeLocalToUtc(newStart, timeZone)
    if (!startsAt.ok) {
      setPanelError('La hora no existe en la zona horaria de la clínica.')
      return
    }
    closeAfter(actions.reschedule.mutateAsync({ id: appointment.id, payload: { starts_at: startsAt.value } }))
  }

  // A finished appointment (attended, cancelled...) has no transitions left: render no
  // footer at all instead of an empty bordered bar.
  const hasActions =
    (allowed.start && canStart) ||
    allowed.arrive ||
    allowed.confirm ||
    allowed.reschedule ||
    allowed.noShow ||
    allowed.cancel

  const footer =
    canUpdate && panel === 'none' && hasActions ? (
      <>
        {allowed.start && canStart && (
          <Button
            className="w-full"
            disabled={busy}
            onClick={() => closeAfter(actions.start.mutateAsync(appointment.id))}
          >
            <Play className="size-4" aria-hidden />
            Iniciar atención
          </Button>
        )}
        <div className="grid grid-cols-2 gap-2">
          {allowed.arrive && (
            <Button
              variant="outline"
              disabled={busy}
              onClick={() => closeAfter(actions.arrive.mutateAsync(appointment.id))}
            >
              <DoorOpen className="size-4" aria-hidden />
              Llegó
            </Button>
          )}
          {allowed.confirm && (
            <Button
              variant="outline"
              disabled={busy}
              onClick={() => closeAfter(actions.confirm.mutateAsync(appointment.id))}
            >
              <CircleCheck className="size-4" aria-hidden />
              Confirmar
            </Button>
          )}
          {allowed.reschedule && (
            <Button variant="outline" disabled={busy} onClick={() => setPanel('reschedule')}>
              <CalendarClock className="size-4" aria-hidden />
              Reprogramar
            </Button>
          )}
          {allowed.noShow && (
            <Button
              variant="outline"
              disabled={busy}
              onClick={() => closeAfter(actions.noShow.mutateAsync(appointment.id))}
            >
              <UserX className="size-4" aria-hidden />
              No asistió
            </Button>
          )}
          {allowed.cancel && (
            <Button variant="outline" disabled={busy} onClick={() => setPanel('cancel')}>
              <X className="size-4" aria-hidden />
              Cancelar
            </Button>
          )}
        </div>
      </>
    ) : undefined

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={childName}
      description={
        <>
          {formatDateOnly(day)} · {formatTenantTime(appointment.starts_at, timeZone)}–
          {formatTenantTime(appointment.ends_at, timeZone)}
        </>
      }
      footer={footer}
    >
      <div className="space-y-5 py-2 text-sm">
        <div className="flex items-center gap-2">
          <StatusPill tone={appointmentStatusTone(statusKey)}>
            {appointment.appointment_status?.name ?? 'Agendada'}
          </StatusPill>
          {appointment.is_walk_in && <StatusPill tone="n">Sin cita</StatusPill>}
        </div>

        <DescriptionList columns={2} variant="plain">
          <DescriptionItem label="Tipo" value={appointment.appointment_type?.name ?? '—'} />
          <DescriptionItem label="Profesional" value={practitionerName} />
          {appointment.arrived_at && (
            <DescriptionItem label="Llegó" value={formatTenantTime(appointment.arrived_at, timeZone)} />
          )}
          {appointment.visit?.receipt_number && (
            <DescriptionItem label="Folio" value={appointment.visit.receipt_number} mono />
          )}
          {appointment.cancellation_reason && (
            <DescriptionItem label="Motivo" value={appointment.cancellation_reason} className="sm:col-span-2" />
          )}
          {appointment.notes && (
            <DescriptionItem
              label="Notas"
              value={<span className="whitespace-pre-line">{appointment.notes}</span>}
              className="sm:col-span-2"
            />
          )}
        </DescriptionList>

        <div className="flex flex-wrap gap-x-4 gap-y-2">
          <Link className="text-[var(--b)] underline-offset-4 hover:underline" to={`/child/${appointment.child_id}`}>
            Ver expediente
          </Link>
          {statusKey === 'fulfilled' && appointment.visit_id && appointment.appointment_type?.key === 'screening' && (
            <Link
              className="text-[var(--b)] underline-offset-4 hover:underline"
              to={`/screening/apply/${appointment.visit_id}`}
            >
              Abrir captura del EDI
            </Link>
          )}
        </div>

        {panel === 'reschedule' && (
          <Field>
            <FieldLabel htmlFor="appointment-reschedule" required>
              Nueva fecha y hora
            </FieldLabel>
            <Input
              id="appointment-reschedule"
              type="datetime-local"
              step={300}
              value={newStart}
              onChange={(event) => {
                setNewStart(event.target.value)
                setPanelError(null)
              }}
            />
            <FieldError>{panelError}</FieldError>
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setPanel('none')} disabled={busy}>
                Volver
              </Button>
              <Button size="sm" onClick={submitReschedule} loading={actions.reschedule.isPending} disabled={busy}>
                Guardar cambio
              </Button>
            </div>
          </Field>
        )}
      </div>

      <ConfirmDialog
        isOpen={panel === 'cancel'}
        title="Cancelar cita"
        description={`${childName} · ${formatDateOnly(day)}`}
        confirmLabel="Cancelar cita"
        cancelLabel="Volver"
        destructive
        loadingLabel="Cancelando…"
        isLoading={busy}
        onConfirm={submitCancel}
        onCancel={() => {
          setPanel('none')
          setPanelError(null)
        }}
      >
        <Field>
          <FieldLabel htmlFor="appointment-cancel-reason" required>
            Motivo de la cancelación
          </FieldLabel>
          <Textarea
            id="appointment-cancel-reason"
            rows={3}
            value={reason}
            onChange={(event) => {
              setReason(event.target.value)
              setPanelError(null)
            }}
            placeholder="Ej. la familia pidió cambiar el día"
          />
          <FieldError>{panelError}</FieldError>
        </Field>
      </ConfirmDialog>
    </Modal>
  )
}
