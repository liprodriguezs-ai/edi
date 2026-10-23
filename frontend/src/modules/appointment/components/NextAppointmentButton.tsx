import { Button } from '@/components/ui/button'
import { useNextAppointmentForm, type AppointmentChild } from '@/modules/appointment/hooks/useNextAppointmentForm'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { CalendarPlus } from 'lucide-react'

/** "Agendar próxima cita": opens the appointment form with the suggested date. */
export function NextAppointmentButton({ child }: { child: AppointmentChild }) {
  const form = useNextAppointmentForm(child)

  return (
    <PermissionGuard permission='create.appointment'>
      <Button variant='outline' onClick={() => void form.open()} disabled={form.isLoading}>
        <CalendarPlus className='size-4' aria-hidden />
        {form.isLoading ? 'Buscando fecha…' : 'Agendar próxima cita'}
      </Button>
      {form.sheet}
    </PermissionGuard>
  )
}
