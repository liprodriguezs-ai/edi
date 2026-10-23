import { Button } from '@/components/ui/button'
import { AppointmentDetailSheet } from '@/modules/appointment/components/AppointmentDetailSheet'
import { AppointmentFormSheet, type AppointmentFormPrefill } from '@/modules/appointment/components/AppointmentFormSheet'
import { AppointmentRowAction } from '@/modules/appointment/components/AppointmentRowAction'
import { useAppointmentActions } from '@/modules/appointment/hooks/useAgenda'
import { suggestionToPrefill, useAppointmentSuggestion } from '@/modules/appointment/hooks/useAppointmentSuggestion'
import type { IAppointment } from '@/modules/appointment/schema'
import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { appointmentStatusTone } from '@/modules/appointment/utils/appointmentStatus'
import { Callout } from '@/shared/components/atoms/Callout'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { formatDateOnly, formatTenantTime, instantToTenantCivilDate, tenantToday } from '@/shared/utils/tenantTime'
import { useQuery } from '@tanstack/react-query'
import { CalendarClock, CalendarPlus } from 'lucide-react'
import { useState } from 'react'

interface ChildAppointmentsPanelProps {
  child: NonNullable<AppointmentFormPrefill['child']>
}

const UPCOMING_LIMIT = 20

/**
 * S10 — pestaña "Citas" del expediente: próxima cita sugerida (S7) y las citas
 * del niño, cada una con su acción (iniciar la atención hoy o abrir la cita
 * para Llegó, Confirmar, Reprogramar, No asistió o Cancelar), igual que en la
 * agenda. Sólo se monta al abrir la pestaña (Radix Tabs no renderiza las
 * inactivas), así que no consulta de más.
 */
export function ChildAppointmentsPanel({ child }: ChildAppointmentsPanelProps) {
  const timeZone = useTenantTimeZone()
  const [prefill, setPrefill] = useState<AppointmentFormPrefill | null>(null)
  const [selected, setSelected] = useState<IAppointment | null>(null)
  const { canCreate, canUpdate, canCreateVisit } = useMultiplePermissions({
    canCreate: 'create.appointment',
    canUpdate: 'update.appointment',
    canCreateVisit: 'create.visit',
  })
  // "Iniciar atención" crea la visita: el servidor exige ambos permisos.
  const canStart = canUpdate && canCreateVisit
  const actions = useAppointmentActions()
  const today = tenantToday(timeZone)

  const suggestion = useAppointmentSuggestion(child.id, canCreate)
  const upcoming = useQuery({
    queryKey: ['appointment', 'child', child.id],
    queryFn: async () => {
      const response = await AppointmentResource.search({
        filters: [{ field: 'child_id', operator: '=', value: child.id }],
        sort: [{ field: 'starts_at', direction: 'desc' }],
        includes: [
          { relation: 'practitioner' },
          { relation: 'practitioner.user' },
          { relation: 'appointment_type' },
          { relation: 'appointment_status' },
          { relation: 'visit' },
        ],
        limit: UPCOMING_LIMIT,
      })
      return response.data
    },
  })

  const renderRow = (appointment: IAppointment) => {
    const day = instantToTenantCivilDate(appointment.starts_at, timeZone)
    return (
      <li key={appointment.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
        <span className="font-mono text-sm tabular-nums">
          {formatDateOnly(day)} · {formatTenantTime(appointment.starts_at, timeZone)}
        </span>
        <span className="text-sm text-muted-foreground">{appointment.appointment_type?.name ?? '—'}</span>
        <span className="text-sm text-muted-foreground">{appointment.practitioner?.user?.name ?? '—'}</span>
        <StatusPill tone={appointmentStatusTone(appointment.appointment_status?.key)}>
          {appointment.appointment_status?.name ?? 'Agendada'}
        </StatusPill>
        <AppointmentRowAction
          appointment={appointment}
          isToday={day === today}
          canStart={canStart}
          isStarting={actions.start.isPending && actions.start.variables === appointment.id}
          context={`del ${formatDateOnly(day)} · ${formatTenantTime(appointment.starts_at, timeZone)}`}
          onStart={(item) => actions.start.mutate(item.id)}
          onSelect={setSelected}
        />
      </li>
    )
  }

  return (
    <div className="space-y-4">
      {canCreate && suggestion.data && (
        <Callout icon={CalendarClock} tone="info">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0 space-y-0.5">
              <p className="text-sm font-semibold">
                {suggestion.data.suggested_date
                  ? `Próxima cita sugerida: ${formatDateOnly(suggestion.data.suggested_date)}`
                  : 'Sin revaloración de rutina'}
              </p>
              <p className="text-sm text-[var(--ink-2)]">{suggestion.data.reason}</p>
            </div>
            <Button size="sm" onClick={() => suggestion.data && setPrefill(suggestionToPrefill(suggestion.data, child))}>
              <CalendarPlus className="size-4" aria-hidden />
              Agendar
            </Button>
          </div>
        </Callout>
      )}

      <Panel
        padding="none"
        title="Citas"
        actions={
          canCreate && (
            <Button size="sm" variant="outline" onClick={() => setPrefill({ child })}>
              <CalendarPlus className="size-4" aria-hidden />
              Agendar cita
            </Button>
          )
        }
      >
        {upcoming.isLoading && <InlineLoading name="child-appointments" className="px-4 py-3" />}
        {!upcoming.isLoading && (upcoming.data ?? []).length === 0 && (
          <InlineEmpty className="px-4 py-3">Este niño no tiene citas registradas.</InlineEmpty>
        )}
        {!upcoming.isLoading && (upcoming.data ?? []).length > 0 && (
          <ul className="divide-y divide-border">{(upcoming.data ?? []).map(renderRow)}</ul>
        )}
      </Panel>

      <AppointmentDetailSheet
        appointment={selected}
        actions={actions}
        canUpdate={canUpdate}
        canStart={canStart}
        onClose={() => setSelected(null)}
      />

      <AppointmentFormSheet
        isOpen={prefill !== null}
        mode="book"
        prefill={prefill ?? undefined}
        onClose={() => setPrefill(null)}
      />
    </div>
  )
}
