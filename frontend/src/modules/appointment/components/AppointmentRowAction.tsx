import { Button } from '@/components/ui/button'
import type { IAppointment } from '@/modules/appointment/schema'
import { availableActions } from '@/modules/appointment/utils/appointmentStatus'
import { isInstantAtOrAfter } from '@/shared/utils/tenantTime'
import { Play } from 'lucide-react'

interface AppointmentRowActionProps {
  appointment: IAppointment
  /** The appointment falls on today's clinic day. */
  isToday: boolean
  canStart: boolean
  isStarting: boolean
  /** Who or when, for the button's accessible name ("de Ana Cruz", "del 21 oct · 09:00"). */
  context: string
  onStart: (_appointment: IAppointment) => void
  onSelect: (_appointment: IAppointment) => void
}

/**
 * The one action of an appointment row: "Iniciar atención" when the server will
 * accept it now, otherwise "Ver cita", which opens every other transition.
 * Shared by the agenda list and the child record so both offer the same step.
 */
export function AppointmentRowAction({
  appointment,
  isToday,
  canStart,
  isStarting,
  context,
  onStart,
  onSelect,
}: AppointmentRowActionProps) {
  const startable =
    canStart && availableActions(appointment, isToday, !isInstantAtOrAfter(appointment.starts_at)).start

  if (startable) {
    return (
      <Button
        size='sm'
        className='min-w-36'
        disabled={isStarting}
        aria-label={`Iniciar atención ${context}`}
        onClick={() => onStart(appointment)}
      >
        <Play className='size-4' aria-hidden />
        Iniciar atención
      </Button>
    )
  }

  return (
    <Button
      size='sm'
      variant='outline'
      className='min-w-36'
      aria-label={`Ver cita ${context}`}
      onClick={() => onSelect(appointment)}
    >
      Ver cita
    </Button>
  )
}
