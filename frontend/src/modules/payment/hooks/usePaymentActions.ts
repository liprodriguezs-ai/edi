import type { IPayment } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import { useMutation } from '@tanstack/react-query'
import { useCallback, useState } from 'react'

export interface UsePaymentActionsOptions {
  /**
   * Se invoca tras CUALQUIER mutación exitosa (validar/rechazar/cancelar) para
   * que el consumidor refresque sus datos: la lista invalida `['payments']`; el
   * detalle hace `refetch` de la query del pago (para que folio/estado se
   * actualicen — validar asigna `receipt_number`).
   */
  onActionSuccess: () => void
}

/**
 * Estado + handlers que consume `PaymentActionDialogs`. Se mantiene UN solo set
 * de diálogos (a nivel de página en la lista; a nivel de página en el detalle):
 * el botón de cada pago selecciona el pago y abre el diálogo correspondiente.
 * Esto evita duplicar diálogos por fila — `ResponsiveDataTable` monta la vista
 * desktop y la mobile a la vez.
 */
export interface PaymentActionDialogState {
  validating: IPayment | null
  rejecting: IPayment | null
  cancelling: IPayment | null
  rejectReason: string
  cancelReason: string
  setRejectReason: (value: string) => void
  setCancelReason: (value: string) => void
  closeValidate: () => void
  closeReject: () => void
  closeCancel: () => void
  confirmValidate: () => void
  confirmReject: () => void
  confirmCancel: () => void
  validatePending: boolean
  rejectPending: boolean
  cancelPending: boolean
}

export interface PaymentActionsState {
  // Openers — los disparan los botones de cada pago.
  requestValidate: (payment: IPayment) => void
  requestReject: (payment: IPayment) => void
  requestCancel: (payment: IPayment) => void
  downloadReceipt: (payment: IPayment) => void
  // Estado por botón (deshabilitar mientras corre la acción).
  downloadingId: string | null
  validatePending: boolean
  rejectPending: boolean
  cancelPending: boolean
  // Estado + handlers para `PaymentActionDialogs` (se renderiza una sola vez).
  dialog: PaymentActionDialogState
}

function errorToast(error: unknown, fallback: string) {
  toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : fallback)
}

/**
 * Lógica compartida de acciones sobre un pago — validar / rechazar / cancelar +
 * descarga de comprobante. La consumen TANTO la lista (acciones por fila, con un
 * único set de diálogos a nivel de página) como el detalle (acciones de un solo
 * pago). Centralizar las mutaciones, sus toasts y los diálogos garantiza que
 * lista y detalle nunca se desincronicen; lo único que difiere es QUÉ botones se
 * muestran (la lista decide por pestaña, el detalle por estado del pago).
 */
export function usePaymentActions({ onActionSuccess }: UsePaymentActionsOptions): PaymentActionsState {
  const [validating, setValidating] = useState<IPayment | null>(null)
  const [rejecting, setRejecting] = useState<IPayment | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const [cancelling, setCancelling] = useState<IPayment | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [downloadingId, setDownloadingId] = useState<string | null>(null)

  const validateMutation = useMutation({
    mutationFn: (payment: IPayment) => PaymentResource.validatePayment(payment.id),
    onSuccess: () => {
      toast.success('Pago validado y aplicado')
      setValidating(null)
      onActionSuccess()
    },
    onError: (error) => errorToast(error, 'No se pudo validar el pago'),
  })

  const rejectMutation = useMutation({
    mutationFn: ({ payment, reason }: { payment: IPayment; reason: string }) =>
      PaymentResource.reject(payment.id, reason),
    onSuccess: () => {
      toast.success('Pago rechazado — se notificó al residente')
      setRejecting(null)
      setRejectReason('')
      onActionSuccess()
    },
    onError: (error) => errorToast(error, 'No se pudo rechazar el pago'),
  })

  const cancelMutation = useMutation({
    mutationFn: ({ payment, reason }: { payment: IPayment; reason: string }) =>
      PaymentResource.cancelPayment(payment.id, reason),
    onSuccess: () => {
      toast.success('Pago cancelado — se revirtieron sus aplicaciones')
      setCancelling(null)
      setCancelReason('')
      onActionSuccess()
    },
    onError: (error) => errorToast(error, 'No se pudo cancelar el pago'),
  })

  const runDownload = useCallback(async (payment: IPayment) => {
    const receipt = payment.media?.find((media) => media.collection_name === 'receipt')
    if (!receipt) {
      return
    }
    setDownloadingId(payment.id)
    try {
      await downloadMediaWithSignedUrl({
        mediaId: receipt.id,
        fileName: receipt.file_name,
        errorMessage: 'No se pudo descargar el comprobante',
      })
    } finally {
      setDownloadingId(null)
    }
  }, [])

  // Openers estables (los setters de useState ya son estables; runDownload es
  // useCallback) para que la lista pueda memoizar el render de acciones por fila
  // sin depender del objeto `actions` completo (que se recrea en cada render).
  const requestValidate = useCallback((payment: IPayment) => setValidating(payment), [])
  const requestReject = useCallback((payment: IPayment) => setRejecting(payment), [])
  const requestCancel = useCallback((payment: IPayment) => setCancelling(payment), [])
  const downloadReceipt = useCallback((payment: IPayment) => void runDownload(payment), [runDownload])

  return {
    requestValidate,
    requestReject,
    requestCancel,
    downloadReceipt,
    downloadingId,
    validatePending: validateMutation.isPending,
    rejectPending: rejectMutation.isPending,
    cancelPending: cancelMutation.isPending,
    dialog: {
      validating,
      rejecting,
      cancelling,
      rejectReason,
      cancelReason,
      setRejectReason,
      setCancelReason,
      closeValidate: () => setValidating(null),
      // Limpiar el motivo al cerrar SIN confirmar (Escape/Cancelar): de lo
      // contrario quedaría precargado y podría enviarse el motivo del pago
      // anterior al siguiente pago (contaminación de una acción de dinero).
      closeReject: () => {
        setRejecting(null)
        setRejectReason('')
      },
      closeCancel: () => {
        setCancelling(null)
        setCancelReason('')
      },
      confirmValidate: () => {
        if (validating) {
          validateMutation.mutate(validating)
        }
      },
      confirmReject: () => {
        if (rejecting) {
          rejectMutation.mutate({ payment: rejecting, reason: rejectReason.trim() })
        }
      },
      confirmCancel: () => {
        if (cancelling) {
          cancelMutation.mutate({ payment: cancelling, reason: cancelReason.trim() })
        }
      },
      validatePending: validateMutation.isPending,
      rejectPending: rejectMutation.isPending,
      cancelPending: cancelMutation.isPending,
    },
  }
}
