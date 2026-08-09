import type { IAmenityReservation } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation } from '@tanstack/react-query'
import { useCallback, useState } from 'react'

export type ReservationActionKind = 'approve' | 'reject' | 'cancel' | 'complete' | 'no_show'

/** Cuáles de las 5 transiciones FSM piden un motivo de texto (espeja el backend). */
const ACTION_NEEDS_REASON: Record<ReservationActionKind, boolean> = {
  approve: false,
  reject: true,
  cancel: true,
  complete: false,
  no_show: false,
}

const SUCCESS_MESSAGE: Record<ReservationActionKind, string> = {
  approve: 'Reservación aprobada — se notificó al residente',
  reject: 'Reservación rechazada — se notificó al residente',
  cancel: 'Reservación cancelada — se revirtieron sus pagos confirmados',
  complete: 'Reservación marcada como completada',
  no_show: 'Reservación marcada como no presentado',
}

function runAction(
  action: ReservationActionKind,
  reservationId: string,
  reason: string
): Promise<IAmenityReservation> {
  switch (action) {
    case 'approve':
      return AmenityReservationResource.approve(reservationId)
    case 'reject':
      return AmenityReservationResource.reject(reservationId, reason)
    case 'cancel':
      return AmenityReservationResource.cancel(reservationId, reason)
    case 'complete':
      return AmenityReservationResource.complete(reservationId)
    case 'no_show':
      return AmenityReservationResource.noShow(reservationId)
  }
}

function errorToast(error: unknown, fallback: string) {
  toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : fallback)
}

export interface ReservationActionDialogState {
  action: ReservationActionKind | null
  reason: string
  setReason: (_value: string) => void
  needsReason: boolean
  isPending: boolean
  close: () => void
  confirm: () => void
}

export interface UseReservationActionsOptions {
  reservation: IAmenityReservation | undefined
  onActionSuccess: () => void
}

export interface ReservationActionsState {
  /** Abre el diálogo de confirmación de la acción indicada. */
  request: (_action: ReservationActionKind) => void
  isPending: boolean
  dialog: ReservationActionDialogState
}

/**
 * Estado + mutación compartidos por las 5 transiciones FSM de una reservación
 * (S7): aprobar/rechazar(motivo)/cancelar(motivo)/completar/no-show. Mirror de
 * `usePaymentActions` — un único diálogo de confirmación (con textarea de
 * motivo cuando aplica) se renderiza una sola vez en el detalle.
 */
export function useReservationActions({
  reservation,
  onActionSuccess,
}: UseReservationActionsOptions): ReservationActionsState {
  const [action, setAction] = useState<ReservationActionKind | null>(null)
  const [reason, setReason] = useState('')

  const mutation = useMutation({
    mutationFn: ({
      kind,
      id,
      reasonValue,
    }: {
      kind: ReservationActionKind
      id: string
      reasonValue: string
    }) => runAction(kind, id, reasonValue),
    onSuccess: (_updated, variables) => {
      toast.success(SUCCESS_MESSAGE[variables.kind])
      setAction(null)
      setReason('')
      onActionSuccess()
    },
    onError: (error) => errorToast(error, 'No se pudo completar la acción'),
  })

  const request = useCallback((next: ReservationActionKind) => setAction(next), [])

  const close = useCallback(() => {
    setAction(null)
    setReason('')
  }, [])

  const confirm = useCallback(() => {
    if (!action || !reservation) return
    mutation.mutate({ kind: action, id: reservation.id, reasonValue: reason.trim() })
  }, [action, reservation, reason, mutation])

  return {
    request,
    isPending: mutation.isPending,
    dialog: {
      action,
      reason,
      setReason,
      needsReason: action ? ACTION_NEEDS_REASON[action] : false,
      isPending: mutation.isPending,
      close,
      confirm,
    },
  }
}
