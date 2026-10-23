import { AppointmentRowAction } from '@/modules/appointment/components/AppointmentRowAction'
import type { IAppointment } from '@/modules/appointment/schema'
import { appointmentStatusKey, appointmentStatusTone } from '@/modules/appointment/utils/appointmentStatus'
import { groupAppointmentsByDay } from '@/modules/appointment/utils/agendaView'
import { childFullName } from '@/modules/child/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { formatTenantTime } from '@/shared/utils/tenantTime'
import { CalendarX2 } from 'lucide-react'
import { Link } from 'react-router-dom'

interface AgendaListViewProps {
  appointments: IAppointment[]
  timeZone: string
  /** Día civil de hoy en la zona del tenant. */
  today: string
  canStart: boolean
  startingId: string | null
  onStart: (_appointment: IAppointment) => void
  onSelect: (_appointment: IAppointment) => void
}

/**
 * Modo lista de la agenda: las citas de los próximos días agrupadas por día,
 * cada una con su estado y la acción que toca (iniciar la atención hoy o ver
 * la cita, donde están las demás transiciones).
 */
export function AgendaListView({
  appointments,
  timeZone,
  today,
  canStart,
  startingId,
  onStart,
  onSelect,
}: AgendaListViewProps) {
  const groups = groupAppointmentsByDay(appointments, timeZone, today)

  if (groups.length === 0) {
    return (
      <div className="border border-border bg-card p-6">
        <InlineEmpty icon={CalendarX2}>No hay citas en estos días.</InlineEmpty>
      </div>
    )
  }

  return (
    <div className="border border-border bg-card">
      {groups.map((group) => (
        <section key={group.day} aria-label={group.label}>
          <h2 className="border-b border-border bg-muted px-4 py-2.5 font-mono text-[11px] font-medium uppercase tracking-[0.14em] text-muted-foreground">
            {group.label}
          </h2>
          <ul>
            {group.appointments.map((appointment) => {
              const childName = appointment.child ? childFullName(appointment.child) : 'Niño sin nombre'
              const statusKey = appointmentStatusKey(appointment)

              return (
                <li
                  key={appointment.id}
                  className="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-border px-4 py-3 last:border-b-0"
                >
                  <span className="w-14 font-mono text-sm font-semibold tabular-nums">
                    {formatTenantTime(appointment.starts_at, timeZone)}
                  </span>
                  <div className="min-w-0 flex-1 basis-52">
                    <Link
                      to={`/child/${appointment.child_id}`}
                      className="font-semibold text-foreground underline-offset-4 hover:underline"
                    >
                      {childName}
                    </Link>
                    <p className="text-sm text-muted-foreground">
                      {appointment.appointment_type?.name ?? 'Cita'} ·{' '}
                      {appointment.practitioner?.user?.name ?? 'Sin profesional'}
                    </p>
                  </div>
                  <StatusPill tone={appointmentStatusTone(statusKey)}>
                    {appointment.appointment_status?.name ?? 'Agendada'}
                  </StatusPill>
                  <AppointmentRowAction
                    appointment={appointment}
                    isToday={group.day === today}
                    canStart={canStart}
                    isStarting={startingId === appointment.id}
                    context={`de ${childName}`}
                    onStart={onStart}
                    onSelect={onSelect}
                  />
                </li>
              )
            })}
          </ul>
        </section>
      ))}
    </div>
  )
}
