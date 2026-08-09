import type { IChargeBatch } from '@/modules/chargeBatch/schema'
import type { IChargeConcept } from '@/modules/chargeConcept/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { moneyToMinorUnits, type IMoney } from '@/shared/utils/money'

export const CHARGE_STATUS = {
  COMPLETED: 'd533b5e0-9b64-4910-812f-a3d49517acc1',
  PARTIAL: 'bdd1e2ad-afa8-4ddf-bbc1-bc870c41f538',
  OVERDUE: '6413740b-696f-4025-910b-0a84a3853c85',
  DUE_SOON: 'a9ebca16-6a08-4316-9b36-980002c49ca2',
  OVERPAID: '88ebed96-a48e-42f3-baf0-e40f57e31aed',
  PENDING: '42ce6f04-3298-46d3-be94-071f38abc3c5',
  PAYMENT_PLAN: '40247d14-a4c5-49d0-a25e-48cdb05a5c64',
} as const

import type { PillTone } from '@/shared/components/atoms/StatusPill'

/** Tono visual por estado de cargo (diseño v3) */
export function chargeStatusTone(chargeStatusId?: string | null): PillTone {
  switch (chargeStatusId) {
    case CHARGE_STATUS.COMPLETED:
      return 'g'
    case CHARGE_STATUS.OVERPAID:
      return 'b'
    case CHARGE_STATUS.PARTIAL:
    case CHARGE_STATUS.DUE_SOON:
    case CHARGE_STATUS.PAYMENT_PLAN:
      return 'y'
    case CHARGE_STATUS.OVERDUE:
      return 'r'
    default:
      return 'b'
  }
}

/**
 * Etiqueta visual del estado de un cargo. NO cambia el FSM (que prioriza "vencido"):
 * cuando un cargo está vencido pero tiene pago parcial, agrega la pista " · parcial"
 * para que se lea que algo ya se pagó. Para el resto devuelve el nombre del estado tal cual.
 */
export function chargeStatusLabel(charge: ICharge, fallback = '—'): string {
  const name = charge.charge_status?.name ?? fallback
  const isOverdue = charge.charge_status_id === CHARGE_STATUS.OVERDUE
  const hasPartialPayment = moneyToMinorUnits(charge.paid_amount) > 0
  return isOverdue && hasPartialPayment ? `${name} · parcial` : name
}

const CHARGE_PERIOD_MONTHS = [
  '',
  'Ene',
  'Feb',
  'Mar',
  'Abr',
  'May',
  'Jun',
  'Jul',
  'Ago',
  'Sep',
  'Oct',
  'Nov',
  'Dic',
]

/** Periodo legible de un cargo a partir de su lote (mes/año), o "Eventual" si no aplica. */
export function formatChargePeriod(charge: ICharge): string {
  const batch = charge.batch
  if (batch?.month && batch?.year) return `${CHARGE_PERIOD_MONTHS[batch.month]} ${batch.year}`
  if (batch?.year) return String(batch.year)
  return 'Eventual'
}

export interface ICharge extends IEntity {
  charge_batch_id?: string | null
  charge_concept_id?: string | null
  unit_id: string
  expected_amount: IMoney | number
  paid_amount: IMoney | number
  discount_amount?: IMoney | number | null
  discount_reason?: string | null
  issued_at?: string | null
  due_date?: string | null
  grace_days?: number | null
  charge_status_id: string
  is_cancelled?: boolean
  cancellation_reason?: string | null
  unit?: { id: string; number?: string | null; full_identifier?: string } | null
  concept?: IChargeConcept | null
  batch?: IChargeBatch | null
  charge_status?: { id: string; name: string; color?: string | null } | null
}

// NOTA: el form de "Cobro directo" ya no tiene schema propio — vive como modo
// dentro de `registerPaymentSchema` (payment/schema.ts, charge_type: 'direct').
