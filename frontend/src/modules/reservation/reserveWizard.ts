import { RESERVATION_MODE, type IAmenity } from '@/modules/amenity/schema'
import { RESERVATION_STATUS, type IAmenityReservation } from '@/modules/reservation/schema'
import { moneyToMinorUnits } from '@/shared/utils/money'

export type ReserveWizardStep = 'unit' | 'datetime' | 'rules' | 'review'

/** Qué hacer justo después de `POST /amenity_reservation` según el estatus REAL devuelto. */
export type PostCreateOutcome = 'payment' | 'approval_pending' | 'confirmed'

/**
 * Decide el siguiente paso tras crear la reservación usando el estatus que el
 * BACKEND devolvió (`reservation.reservation_status_id`), nunca la config de
 * la amenidad (`has_cost`/`requires_approval`). Una amenidad `has_cost` cuya
 * reservación cae en `PENDING_APPROVAL` (porque también `requires_approval`)
 * NO puede pagarse todavía — el backend rechaza el pago con 422 hasta que un
 * admin apruebe. `PENDING_PAYMENT` es el ÚNICO estatus que habilita encadenar
 * el sheet de pago; `PENDING_APPROVAL` solo informa que falta aprobación;
 * cualquier otro estatus (ej. `CONFIRMED`, sin costo ni aprobación) ya quedó
 * resuelto.
 */
export function resolvePostCreateOutcome(reservationStatusId: string): PostCreateOutcome {
  if (reservationStatusId === RESERVATION_STATUS.PENDING_PAYMENT) return 'payment'
  if (reservationStatusId === RESERVATION_STATUS.PENDING_APPROVAL) return 'approval_pending'
  return 'confirmed'
}

/** Amenidad por horarios fijos (espeja `Amenity::reservation_mode_id === ReservationMode::BY_SLOT`). */
export function isBySlotAmenity(amenity: Pick<IAmenity, 'reservation_mode_id'>): boolean {
  return amenity.reservation_mode_id === RESERVATION_MODE.BY_SLOT
}

/**
 * Pasos del wizard según los datos de la amenidad + cuántas unidades propias
 * tiene el residente. `unit` solo aparece si tiene más de una (con una sola,
 * se preselecciona sin pedir nada); `rules` solo si la amenidad tiene reglamento.
 */
export function buildReserveWizardSteps(
  amenity: Pick<IAmenity, 'rules_text'>,
  ownUnitsCount: number
): ReserveWizardStep[] {
  const steps: ReserveWizardStep[] = []
  if (ownUnitsCount > 1) steps.push('unit')
  steps.push('datetime')
  if (amenity.rules_text) steps.push('rules')
  steps.push('review')
  return steps
}

/** El paso "fecha/horario" avanza con fecha elegida y, si es `by_slot`, horario elegido. */
export function canProceedFromDateTimeStep(
  amenity: Pick<IAmenity, 'reservation_mode_id'>,
  selectedDate: string | null,
  selectedSlotId: string | null
): boolean {
  if (!selectedDate) return false
  if (isBySlotAmenity(amenity)) return Boolean(selectedSlotId)
  return true
}

/** El paso "reglamento" solo avanza si el residente marcó que lo aceptó. */
export function canProceedFromRulesStep(rulesAccepted: boolean): boolean {
  return rulesAccepted
}

export interface ReserveDepositHint {
  /** Monto mínimo exigido en esta primera captura (depósito si aplica, o el costo completo). */
  minimumMinor: number
  /** Costo total de la amenidad/reservación. */
  totalMinor: number
  /** true cuando `minimumMinor < totalMinor` (hay saldo restante tras el depósito). */
  isPartial: boolean
}

/**
 * Entradas agnósticas de la fuente: montos ya en minor units, o `null` cuando
 * no aplica (sin costo / sin depósito). Se puede alimentar tanto desde una
 * `IAmenity` (pre-creación, en el wizard) como desde el SNAPSHOT congelado de
 * una `IAmenityReservation` ya creada (pago posterior, S16) — los dos casos
 * usan el MISMO cálculo, solo cambia de dónde vienen los números.
 */
export interface ReserveCostInputs {
  costAmountMinor: number | null
  depositAmountMinor: number | null
}

/** Resumen de costo/depósito — null si no hay costo. */
export function reserveDepositHint(inputs: ReserveCostInputs): ReserveDepositHint | null {
  if (inputs.costAmountMinor == null) return null

  const totalMinor = inputs.costAmountMinor

  if (inputs.depositAmountMinor != null) {
    return {
      minimumMinor: inputs.depositAmountMinor,
      totalMinor,
      isPartial: inputs.depositAmountMinor < totalMinor,
    }
  }

  return { minimumMinor: totalMinor, totalMinor, isPartial: false }
}

/**
 * Monto sugerido para el sheet de pago earmark de una reservación YA creada
 * (`SubmitReservationPaymentSheet`). Sugiere el depósito mínimo SOLO en la
 * PRIMERA captura — cuando `outstandingMinor` todavía es igual al costo total
 * congelado, es decir nada se ha liquidado todavía. En cualquier otro caso
 * (pago de saldo/balance tras un depósito ya aplicado, o una amenidad sin
 * depósito) sugiere el saldo pendiente completo. `reserveDepositHint().minimumMinor`
 * por sí solo es agnóstico del orden de pago — reusarlo también para el
 * default del pago de saldo hacía que el residente viera de nuevo el monto
 * del depósito en vez del saldo restante, llevando a un pago insuficiente.
 */
export function suggestedPaymentMinor(inputs: ReserveCostInputs, outstandingMinor: number): number {
  const hint = reserveDepositHint(inputs)
  if (!hint) return outstandingMinor
  const isFirstPayment = outstandingMinor === hint.totalMinor
  return isFirstPayment ? hint.minimumMinor : outstandingMinor
}

/** Construye `ReserveCostInputs` a partir de una `IAmenity` (paso de revisión del wizard, pre-creación). */
export function costInputsFromAmenity(
  amenity: Pick<IAmenity, 'has_cost' | 'cost_amount' | 'requires_deposit' | 'deposit_amount'>
): ReserveCostInputs {
  return {
    costAmountMinor: amenity.has_cost ? moneyToMinorUnits(amenity.cost_amount) : null,
    depositAmountMinor: amenity.requires_deposit ? moneyToMinorUnits(amenity.deposit_amount) : null,
  }
}

/**
 * Construye `ReserveCostInputs` a partir de una `IAmenityReservation` YA
 * creada (pago posterior — S15's follow-up sheet o S16's "pagar saldo"): usa
 * el SNAPSHOT congelado en la reservación (`cost_amount`/`deposit_amount`),
 * NUNCA la config actual de la amenidad — un cambio posterior en la amenidad
 * no debe alterar retroactivamente lo que esta reservación ya congeló.
 */
export function costInputsFromReservation(
  reservation: Pick<IAmenityReservation, 'cost_amount' | 'deposit_amount'>
): ReserveCostInputs {
  return {
    costAmountMinor:
      reservation.cost_amount != null ? moneyToMinorUnits(reservation.cost_amount) : null,
    depositAmountMinor:
      reservation.deposit_amount != null ? moneyToMinorUnits(reservation.deposit_amount) : null,
  }
}

/**
 * Payload de `POST /amenity_reservation` — espeja `AmenityReservationCreateRequest`.
 * Documenta la forma, pero se usa solo como referencia: `buildReservationCreatePayload`
 * devuelve `Record<string, unknown>` porque ese es el `TForm` con el que
 * `AmenityReservationResource` está instanciado (interfaces con propiedades
 * conocidas pero SIN índice explícito no son asignables a `Record<string, unknown>`).
 */
export interface ReservationCreatePayload {
  amenity_id: string
  unit_id: string
  reservation_date: string
  slot_id?: string
  rules_accepted?: boolean
}

export function buildReservationCreatePayload(params: {
  amenityId: string
  unitId: string
  reservationDate: string
  slotId: string | null
  rulesAccepted: boolean
}): Record<string, unknown> {
  const payload: ReservationCreatePayload = {
    amenity_id: params.amenityId,
    unit_id: params.unitId,
    reservation_date: params.reservationDate,
  }
  if (params.slotId) payload.slot_id = params.slotId
  if (params.rulesAccepted) payload.rules_accepted = true
  return { ...payload }
}
