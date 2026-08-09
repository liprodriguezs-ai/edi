import { Button } from '@/components/ui/button'
import type { IPayment } from '@/modules/payment/schema'
import { Ban, Check, Download, X } from 'lucide-react'

/**
 * Qué botones mostrar. La VISIBILIDAD la decide el consumidor porque difiere por
 * contexto: la lista la calcula por pestaña (la cola "Pendientes" muestra
 * validar/rechazar; el resto muestra cancelar), y el detalle por el estado real
 * del pago (`payment_status_id`).
 */
export interface PaymentActionVisibility {
  download: boolean
  validate: boolean
  reject: boolean
  cancel: boolean
}

export interface PaymentActionButtonsProps {
  payment: IPayment
  show: PaymentActionVisibility
  downloadingId: string | null
  validatePending: boolean
  rejectPending: boolean
  cancelPending: boolean
  onDownload: () => void
  onReject: () => void
  onValidate: () => void
  onCancel: () => void
  /**
   * `'compact'` (default): pills chicos para la fila de la tabla de la lista.
   * `'default'`: botones de tamaño normal para el toolbar del detalle.
   */
  size?: 'compact' | 'default'
}

/**
 * Fila de acciones de un pago (descargar comprobante / validar / rechazar /
 * cancelar). Presentacional puro: el estado y las mutaciones viven en
 * `usePaymentActions`, compartido por lista y detalle.
 */
export function PaymentActionButtons({
  payment,
  show,
  downloadingId,
  validatePending,
  rejectPending,
  cancelPending,
  onDownload,
  onReject,
  onValidate,
  onCancel,
  size = 'compact',
}: PaymentActionButtonsProps) {
  const compact = size === 'compact'
  const buttonSize = compact ? 'sm' : 'default'
  const pill = compact ? 'h-7 rounded-full px-2.5 text-[12px]' : 'rounded-full'
  const iconClass = compact ? 'size-3.5' : 'size-4'
  const destructive = `${pill} text-destructive hover:text-destructive`

  return (
    <div className={compact ? 'flex justify-end gap-1.5' : 'flex flex-wrap gap-2'}>
      {show.download && (
        <Button
          size={buttonSize}
          variant="outline"
          className={pill}
          onClick={onDownload}
          disabled={downloadingId === payment.id}
          aria-label="Descargar comprobante de pago"
        >
          <Download className={iconClass} />
          Comprobante
        </Button>
      )}
      {show.reject && (
        <Button
          size={buttonSize}
          variant="outline"
          className={destructive}
          onClick={onReject}
          disabled={rejectPending}
          aria-label="Rechazar pago"
        >
          <X className={iconClass} />
          Rechazar
        </Button>
      )}
      {show.validate && (
        <Button
          size={buttonSize}
          className={pill}
          onClick={onValidate}
          disabled={validatePending}
          aria-label="Validar pago"
        >
          <Check className={iconClass} />
          Validar y aplicar
        </Button>
      )}
      {show.cancel && (
        <Button
          size={buttonSize}
          variant="outline"
          className={destructive}
          onClick={onCancel}
          disabled={cancelPending}
          aria-label="Cancelar pago"
        >
          <Ban className={iconClass} />
          Cancelar pago
        </Button>
      )}
    </div>
  )
}
