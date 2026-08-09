import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import type {
  ReservationActionDialogState,
  ReservationActionKind,
} from '@/modules/reservation/hooks/useReservationActions'

const TITLE: Record<ReservationActionKind, string> = {
  approve: 'Aprobar reservación',
  reject: 'Rechazar reservación',
  cancel: 'Cancelar reservación',
  complete: 'Marcar como completada',
  no_show: 'Marcar como no presentado',
}

const DESCRIPTION: Record<ReservationActionKind, string> = {
  approve:
    'Se notificará al residente. Si la amenidad tiene costo, la reservación pasará a espera de pago.',
  reject:
    'El residente recibirá una notificación con el motivo. El horario queda libre de inmediato.',
  cancel:
    'Se revertirá cualquier pago confirmado (saldo de la cuenta y aplicaciones) y el horario quedará libre. Esta acción queda en auditoría.',
  complete: 'La reservación se marcará como completada.',
  no_show:
    'Se registrará que el residente no se presentó ni usó la amenidad. La reservación se cierra; no afecta el saldo ni el depósito.',
}

const CONFIRM_LABEL: Record<ReservationActionKind, string> = {
  approve: 'Aprobar',
  reject: 'Rechazar reservación',
  cancel: 'Cancelar reservación',
  complete: 'Marcar completada',
  no_show: 'Marcar no presentado',
}

const DESTRUCTIVE_ACTIONS: readonly ReservationActionKind[] = ['reject', 'cancel']

export interface ReservationActionDialogsProps {
  dialog: ReservationActionDialogState
}

/**
 * Diálogo único de confirmación para las 5 transiciones FSM de una
 * reservación (S7). Mirror de `PaymentActionDialogs`: se renderiza UNA sola
 * vez en el detalle y cambia de copy/campo de motivo según `dialog.action`.
 */
export function ReservationActionDialogs({ dialog }: ReservationActionDialogsProps) {
  const { action } = dialog
  const isOpen = Boolean(action)
  const isDestructive = action != null && DESTRUCTIVE_ACTIONS.includes(action)
  const reasonTooShort = dialog.needsReason && dialog.reason.trim().length < 5

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && dialog.close()}>
      <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
        <DialogHeader className='px-6 pt-6'>
          <DialogTitle>{action ? TITLE[action] : ''}</DialogTitle>
        </DialogHeader>
        <div className='px-6 py-2'>
          <p className='mb-3 text-[13px] text-[var(--ink-2)]'>
            {action ? DESCRIPTION[action] : ''}
          </p>
          {dialog.needsReason && (
            <Textarea
              rows={3}
              value={dialog.reason}
              onChange={(e) => dialog.setReason(e.target.value)}
              placeholder='Motivo (mínimo 5 caracteres)…'
              className='resize-none'
            />
          )}
        </div>
        <DialogFooter className='px-6 pb-6'>
          <Button variant='outline' onClick={dialog.close} disabled={dialog.isPending}>
            Volver
          </Button>
          <Button
            variant={isDestructive ? 'destructive' : 'default'}
            disabled={dialog.isPending || reasonTooShort}
            onClick={dialog.confirm}
          >
            {dialog.isPending ? 'Procesando…' : action ? CONFIRM_LABEL[action] : ''}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
