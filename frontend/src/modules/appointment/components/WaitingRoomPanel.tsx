import { Button } from '@/components/ui/button'
import type { IAppointment } from '@/modules/appointment/schema'
import { childFullName } from '@/modules/child/schema'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { formatTenantTime } from '@/shared/utils/tenantTime'
import { Play } from 'lucide-react'

interface WaitingRoomPanelProps {
  appointments: IAppointment[]
  isLoading: boolean
  canStart: boolean
  startingId: string | null
  onStart: (_appointment: IAppointment) => void
  onSelect: (_appointment: IAppointment) => void
}

/**
 * Sala de espera (S5/S6): niños de hoy que ya llegaron, en orden de llegada.
 * "Iniciar atención" crea la visita con su folio y abre la captura del EDI.
 */
export function WaitingRoomPanel({
  appointments,
  isLoading,
  canStart,
  startingId,
  onStart,
  onSelect,
}: WaitingRoomPanelProps) {
  const timeZone = useTenantTimeZone()

  return (
    <section aria-labelledby="waiting-room-title" className="border border-border bg-card">
      <header className="flex items-baseline justify-between border-b border-border px-4 py-3">
        <h2 id="waiting-room-title" className="text-sm font-semibold">
          Sala de espera
        </h2>
        <span className="text-xs text-[var(--ink-3)]">
          {appointments.length === 1 ? '1 niño' : `${appointments.length} niños`}
        </span>
      </header>

      {isLoading && <InlineLoading name="waiting-room" className="px-4 py-6" />}

      {!isLoading && appointments.length === 0 && (
        <InlineEmpty className="px-4 py-6">
          Nadie está esperando. Marca “Llegó” en la cita o usa “Registrar sin cita”.
        </InlineEmpty>
      )}

      {!isLoading && appointments.length > 0 && (
        <ol className="divide-y divide-[var(--line-2)]">
          {appointments.map((appointment, index) => (
            <li key={appointment.id} className="flex flex-wrap items-start gap-x-3 gap-y-2 px-4 py-3">
              <span className="w-5 pt-0.5 text-right text-xs tabular-nums text-[var(--ink-3)]">{index + 1}</span>
              <button
                type="button"
                className="min-w-0 flex-1 text-left"
                onClick={() => onSelect(appointment)}
              >
                <span className="block text-sm font-medium break-words">
                  {appointment.child ? childFullName(appointment.child) : 'Niño sin nombre'}
                </span>
                <span className="block text-xs text-[var(--ink-3)]">
                  Llegó {appointment.arrived_at ? formatTenantTime(appointment.arrived_at, timeZone) : '—'}
                  {' · '}
                  {appointment.practitioner?.user?.name ?? 'Sin profesional'}
                  {appointment.is_walk_in ? ' · sin cita' : ''}
                </span>
              </button>
              {canStart && (
                <Button
                  size="sm"
                  className="ml-8 w-[calc(100%-2rem)]"
                  disabled={startingId !== null}
                  onClick={() => onStart(appointment)}
                  aria-label={`Iniciar atención de ${appointment.child ? childFullName(appointment.child) : 'este niño'}`}
                >
                  <Play className="size-4" aria-hidden />
                  {startingId === appointment.id ? 'Iniciando…' : 'Atender'}
                </Button>
              )}
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
