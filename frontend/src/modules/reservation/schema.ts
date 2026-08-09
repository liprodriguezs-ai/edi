import type { ICharge } from '@/modules/charge/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { z } from 'zod'

/**
 * Catálogo CENTRAL (UUIDs fijos, sembrados por CatalogSeeder) del FSM de
 * reservaciones. Espeja `Modules\AmenityReservations\Models\ReservationStatus`.
 */
export const RESERVATION_STATUS = {
  PENDING_APPROVAL: '456b615d-5850-4227-a27b-3bdbdf8a4382',
  PENDING_PAYMENT: '9d539bb7-c391-44e4-be65-d98eab77987d',
  CONFIRMED: 'f0b6626d-c020-4ad6-81ae-f8efc94be927',
  CANCELLED: '21702d3e-69d5-4dc0-a92b-7a9ead5b1add',
  REJECTED: '8d9e2a44-6275-4395-aebd-f718711f5626',
  COMPLETED: 'b0ae85fc-ad98-4cce-b4c4-1a7e089c78be',
  NO_SHOW: '1210de11-e939-4814-aa48-6661a40689e5',
} as const

/** Estatuses desde los que la reservación aún se puede cancelar (espeja ReservationService::ACTIVE_STATUSES). */
export const RESERVATION_ACTIVE_STATUSES: readonly string[] = [
  RESERVATION_STATUS.PENDING_APPROVAL,
  RESERVATION_STATUS.PENDING_PAYMENT,
  RESERVATION_STATUS.CONFIRMED,
]

/** Estatuses desde los que se puede aplicar un cargo adicional (espeja AmenitySurchargeService::SURCHARGEABLE_STATUSES). */
export const RESERVATION_SURCHARGEABLE_STATUSES: readonly string[] = [
  RESERVATION_STATUS.CONFIRMED,
  RESERVATION_STATUS.COMPLETED,
]

/**
 * Estatuses en los que un saldo pendiente todavía se puede pagar. Excluye
 * terminales (cancelada/rechazada/completada/no-show) — evita que un
 * `amount_outstanding` congelado (ver ReservationService::cancel()/expire())
 * muestre un botón "Pagar" sobre una reservación muerta.
 */
export const RESERVATION_PAYABLE_STATUSES: readonly string[] = [
  RESERVATION_STATUS.PENDING_PAYMENT,
  RESERVATION_STATUS.CONFIRMED,
]

/** Estatuses terminales: la reservación ya no está viva (espeja el complemento de RESERVATION_ACTIVE_STATUSES). */
export const RESERVATION_TERMINAL_STATUSES: readonly string[] = [
  RESERVATION_STATUS.CANCELLED,
  RESERVATION_STATUS.REJECTED,
  RESERVATION_STATUS.COMPLETED,
  RESERVATION_STATUS.NO_SHOW,
]

/** true si el estatus es terminal (cancelada/rechazada/completada/no-show). */
export function isTerminalReservationStatus(reservationStatusId?: string | null): boolean {
  if (!reservationStatusId) return false
  return RESERVATION_TERMINAL_STATUSES.includes(reservationStatusId)
}

/**
 * BUG FIX (E2E QA round 2 — residual de round 1): en estatuses terminales,
 * `amount_outstanding` puede quedar CONGELADO en un valor > 0 (ver
 * ReservationService::cancel()/expire()) — mostrarlo confunde al usuario
 * porque el saldo ya no significa nada sobre una reservación muerta. La UI
 * siempre lo pinta como $0 para esos estatuses, sin importar el valor
 * almacenado (las acciones de pago ya estaban gateadas por
 * RESERVATION_PAYABLE_STATUSES desde el fix de round 1).
 */
export function displayableOutstandingAmount(
  reservationStatusId: string | null | undefined,
  amountOutstanding: IMoney | number | string | null | undefined
): IMoney | number | string {
  return isTerminalReservationStatus(reservationStatusId) ? 0 : (amountOutstanding ?? 0)
}

/** Tono visual del pill de estado: verde confirmado/completado, ámbar en espera, rojo cancelado/rechazado/no-show. */
export function reservationStatusTone(reservationStatusId?: string | null): PillTone {
  switch (reservationStatusId) {
    case RESERVATION_STATUS.CONFIRMED:
    case RESERVATION_STATUS.COMPLETED:
      return 'g'
    case RESERVATION_STATUS.PENDING_APPROVAL:
    case RESERVATION_STATUS.PENDING_PAYMENT:
      return 'y'
    default:
      return 'r'
  }
}

/**
 * Fecha límite de pago relevante para el estatus actual de la reservación:
 * `payment_deadline` mientras espera el primer pago (PENDING_PAYMENT),
 * `balance_due_date` mientras espera el saldo tras confirmarse con depósito
 * (CONFIRMED). `null` en cualquier otro estatus — no hay vencimiento que
 * mostrarle al residente.
 */
export function reservationPaymentDueDate(
  reservation: Pick<
    IAmenityReservation,
    'reservation_status_id' | 'payment_deadline' | 'balance_due_date'
  >
): string | null {
  if (reservation.reservation_status_id === RESERVATION_STATUS.PENDING_PAYMENT) {
    return reservation.payment_deadline ?? null
  }
  if (reservation.reservation_status_id === RESERVATION_STATUS.CONFIRMED) {
    return reservation.balance_due_date ?? null
  }
  return null
}

interface INamedCatalog {
  id: string
  name: string
}

export interface IReservationSurcharge extends IEntity {
  reservation_id: string
  charge_id: string
  charge_concept_id: string
  amount: IMoney | number
  description?: string
  charge?: ICharge | null
  charge_concept?: INamedCatalog | null
}

export interface IAmenityReservation extends IEntity {
  amenity_id: string
  unit_id: string
  user_id?: string | null
  reservation_date: string
  slot_id?: string | null
  start_at?: string | null
  end_at?: string | null
  reservation_status_id: string
  holds_slot?: boolean
  approved_by?: string | null
  approved_at?: string | null
  rejected_reason?: string | null
  cancelled_by?: string | null
  cancelled_at?: string | null
  cancellation_reason?: string | null
  rules_accepted_at?: string | null
  cost_amount?: IMoney | number | null
  deposit_amount?: IMoney | number | null
  amount_outstanding?: IMoney | number | null
  balance_due_date?: string | null
  payment_deadline?: string | null
  /** Computed by AmenityReservationController (withExists) — true when a PENDING_VALIDATION earmark payment exists. */
  has_pending_payment?: boolean
  amenity?: {
    id: string
    name: string
    bank_account_id?: string | null
    bank_account?: INamedCatalog | null
  } | null
  unit?: { id: string; number?: string | null; full_identifier?: string | null } | null
  slot?: { id: string; start_time: string; end_time: string; weekday: number } | null
  reservation_status?: INamedCatalog | null
  surcharges?: IReservationSurcharge[]
}

/** Alta de un cargo adicional (S9 — incidente/daño). Monto en PESOS; se convierte a minor units al enviar. */
export const surchargeFormSchema = z.object({
  charge_concept_id: z.string().min(1, 'El concepto es requerido'),
  amount: z.number({ message: 'El monto es requerido' }).positive('El monto debe ser mayor a 0'),
  description: z.string().max(500).optional(),
})

export type SurchargeFormValues = z.infer<typeof surchargeFormSchema>

/**
 * Entrada del calendario de OCUPACIÓN anonimizado (comité de vigilancia — S18).
 * Espeja el shape devuelto por `AmenityOccupancyService`/
 * `AmenityReservationController::occupancy()`: SOLO identidad de la amenidad +
 * fecha/horario + un conteo occupied/capacity. Nunca `unit_id`, `user_id` ni el
 * id de la reservación — no hay forma de saber QUIÉN reservó desde este shape.
 */
export interface IAmenityOccupancyEntry {
  amenity_id: string
  amenity_name: string
  reservation_date: string
  slot_id: string | null
  start_at: string | null
  end_at: string | null
  occupied: number
  capacity: number
}

/** Registro de pago de una reservación (admin, earmark). Monto en PESOS. */
export const registerReservationPaymentSchema = z.object({
  paid_at: z.string().min(1, 'La fecha es requerida'),
  total_amount: z
    .number({ message: 'El monto es requerido' })
    .positive('El monto debe ser mayor a 0'),
  payment_method_id: z.string().min(1, 'El método es requerido'),
  reference: z.string().max(255).optional(),
  physical_receipt_number: z.string().max(100).optional(),
  notes: z.string().max(2000).optional(),
})

export type RegisterReservationPaymentValues = z.infer<typeof registerReservationPaymentSchema>
