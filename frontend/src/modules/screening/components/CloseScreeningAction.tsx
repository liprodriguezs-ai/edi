import { Button } from '@/components/ui/button'
import { useNextAppointmentForm } from '@/modules/appointment/hooks/useNextAppointmentForm'
import type { AppointmentFormPrefill } from '@/modules/appointment/components/AppointmentFormSheet'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { screeningErrorMessage } from '@/modules/screening/utils/screeningErrors'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { useState } from 'react'

interface CloseScreeningActionProps {
  screeningId: string
  child: NonNullable<AppointmentFormPrefill['child']> | null | undefined
  /** Runs once the test is closed and, when offered, the appointment form is closed. */
  onDone: () => void
}

/**
 * Step 3 in one action: closes the scored Prueba EDI (it becomes read-only) and
 * right away opens the next appointment with the date the server suggests.
 */
export function CloseScreeningAction({ screeningId, child, onDone }: CloseScreeningActionProps) {
  const canClose = usePermissions('update.screening')
  const canBook = usePermissions('create.appointment')
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [isClosing, setIsClosing] = useState(false)
  const appointment = useNextAppointmentForm(child ?? null, onDone)
  const offersAppointment = canBook && !!child

  if (!canClose) return null

  const handleClose = async () => {
    setIsClosing(true)
    try {
      await ScreeningResource.complete(screeningId)
      toast.success('Prueba EDI cerrada')
      setConfirmOpen(false)
      if (offersAppointment) await appointment.open()
      else onDone()
    } catch (err) {
      toast.error(screeningErrorMessage(err, 'No se pudo cerrar la Prueba EDI'))
    } finally {
      setIsClosing(false)
    }
  }

  const label = offersAppointment ? 'Cerrar prueba y agendar cita' : 'Cerrar prueba'

  return (
    <>
      <Button onClick={() => setConfirmOpen(true)} disabled={isClosing || appointment.isLoading}>
        {label}
      </Button>
      <ConfirmDialog
        isOpen={confirmOpen}
        title='Cerrar Prueba EDI'
        description={
          offersAppointment
            ? 'Al cerrarla ya no podrás cambiar respuestas. Después te proponemos la fecha de la próxima cita según el resultado.'
            : 'Al cerrarla ya no podrás cambiar respuestas.'
        }
        confirmLabel='Cerrar prueba'
        loadingLabel='Cerrando…'
        isLoading={isClosing}
        onConfirm={() => void handleClose()}
        onCancel={() => setConfirmOpen(false)}
      />
      {offersAppointment && appointment.sheet}
    </>
  )
}
