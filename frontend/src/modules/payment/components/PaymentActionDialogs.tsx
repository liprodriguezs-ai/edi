import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import type { PaymentActionDialogState } from '@/modules/payment/hooks/usePaymentActions'

export interface PaymentActionDialogsProps {
  dialog: PaymentActionDialogState
}

/**
 * Diálogos de confirmación de las acciones de pago (§8.5.3): validar y aplicar,
 * rechazar (pide motivo) y cancelar (pide motivo). Se renderiza UNA sola vez por
 * página y se abre con el pago seleccionado vía `usePaymentActions`. Compartido
 * por lista y detalle para que el copy y la validación de motivo no se
 * desincronicen.
 */
export function PaymentActionDialogs({ dialog }: PaymentActionDialogsProps) {
  return (
    <>
      {/* validación con confirmación (§8.5.3) */}
      <Dialog
        open={Boolean(dialog.validating)}
        onOpenChange={(open) => !open && dialog.closeValidate()}
      >
        <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
          <DialogHeader className='px-6 pt-6'>
            <DialogTitle>Validar y aplicar pago</DialogTitle>
          </DialogHeader>
          <div className='px-6 py-2'>
            <p className='text-[13px] text-[var(--ink-2)]'>
              El pago se confirmará y se aplicará automáticamente: a su destino etiquetado si lo
              tiene (p. ej. un proyecto), o a los cargos pendientes de la unidad en orden FIFO. El
              ingreso se sumará al saldo de la cuenta y se notificará al residente.
            </p>
          </div>
          <DialogFooter className='px-6 pb-6'>
            <Button
              variant='outline'
              onClick={dialog.closeValidate}
              disabled={dialog.validatePending}
            >
              Cancelar
            </Button>
            <Button disabled={dialog.validatePending} onClick={dialog.confirmValidate}>
              {dialog.validatePending ? 'Validando…' : 'Validar y aplicar'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* rechazo con motivo (§8.5.3) */}
      <Dialog
        open={Boolean(dialog.rejecting)}
        onOpenChange={(open) => !open && dialog.closeReject()}
      >
        <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
          <DialogHeader className='px-6 pt-6'>
            <DialogTitle>Rechazar pago</DialogTitle>
          </DialogHeader>
          <div className='px-6 py-2'>
            <p className='mb-3 text-[13px] text-[var(--ink-2)]'>
              El residente recibirá una notificación con el motivo y podrá volver a subir su
              comprobante.
            </p>
            <Textarea
              rows={3}
              value={dialog.rejectReason}
              onChange={(e) => dialog.setRejectReason(e.target.value)}
              placeholder='Motivo del rechazo (obligatorio)…'
              className='resize-none'
            />
          </div>
          <DialogFooter className='px-6 pb-6'>
            <Button variant='outline' onClick={dialog.closeReject} disabled={dialog.rejectPending}>
              Cancelar
            </Button>
            <Button
              variant='destructive'
              disabled={dialog.rejectPending || dialog.rejectReason.trim().length === 0}
              onClick={dialog.confirmReject}
            >
              {dialog.rejectPending ? 'Rechazando…' : 'Rechazar pago'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* cancelación con reversa de aplicaciones (§8.5) */}
      <Dialog
        open={Boolean(dialog.cancelling)}
        onOpenChange={(open) => !open && dialog.closeCancel()}
      >
        <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
          <DialogHeader className='px-6 pt-6'>
            <DialogTitle>Cancelar pago</DialogTitle>
          </DialogHeader>
          <div className='px-6 py-2'>
            <p className='mb-3 text-[13px] text-[var(--ink-2)]'>
              Se revertirán todas las aplicaciones a cargos y, si el pago estaba confirmado, el
              saldo de la cuenta. Esta acción queda en auditoría.
            </p>
            <Textarea
              rows={3}
              value={dialog.cancelReason}
              onChange={(e) => dialog.setCancelReason(e.target.value)}
              placeholder='Motivo de la cancelación (mínimo 5 caracteres)…'
              className='resize-none'
            />
          </div>
          <DialogFooter className='px-6 pb-6'>
            <Button variant='outline' onClick={dialog.closeCancel} disabled={dialog.cancelPending}>
              Volver
            </Button>
            <Button
              variant='destructive'
              disabled={dialog.cancelPending || dialog.cancelReason.trim().length < 5}
              onClick={dialog.confirmCancel}
            >
              {dialog.cancelPending ? 'Cancelando…' : 'Cancelar pago'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
