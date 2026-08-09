import type { ICharge } from '@/modules/charge/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { z } from 'zod'

export const PAYMENT_STATUS = {
  CONFIRMED: '31095c7b-989c-423f-bd1e-bad68f65fbc7',
  PENDING_VALIDATION: 'f122bfe6-1c08-45fc-ac16-399df1b9d97e',
  CANCELLED: '12947ed0-5602-45dc-a6b7-5edfa7c07584',
} as const

export const PAYMENT_METHOD = {
  CASH: 'cc956c54-f78f-454c-83da-60cf9641139b',
  TRANSFER: 'e35bbfd8-4c81-4ff2-ba38-ba4966447df9',
  DEPOSIT: '016887ae-9986-4553-94e2-ba6b253e2399',
  CHECK: 'e72f8440-fd1e-43be-a323-07980d54a677',
} as const

/** Registry keys de pagos con destino etiquetado — deben coincidir con los EARMARK_TYPE del backend. */
export const EARMARK_TYPE = {
  PROJECT_CONTRIBUTION: 'project_contribution',
  AMENITY_RESERVATION: 'amenity_reservation',
} as const

export type EarmarkType = (typeof EARMARK_TYPE)[keyof typeof EARMARK_TYPE]

/** Tono visual del pill por estado de pago: verde confirmado, ámbar pendiente, rojo cancelado. */
export function paymentStatusTone(paymentStatusId?: string | null): PillTone {
  switch (paymentStatusId) {
    case PAYMENT_STATUS.CONFIRMED:
      return 'g'
    case PAYMENT_STATUS.PENDING_VALIDATION:
      return 'y'
    default:
      return 'r'
  }
}

export interface IPaymentApplication extends IEntity {
  payment_id: string
  charge_id: string
  applied_amount: IMoney | number
  charge?: ICharge | null
}

export interface IPayment extends IEntity {
  unit_id: string
  payer_user_id?: string | null
  paid_at: string
  total_amount: IMoney | number
  payment_method_id: string
  bank_account_id?: string | null
  reference?: string | null
  receipt_number?: string | null
  physical_receipt_number?: string | null
  payment_status_id: string
  cancellation_reason?: string | null
  notes?: string | null
  earmark_type?: EarmarkType | null
  earmark_id?: string | null
  unit?: { id: string; number?: string | null; full_identifier?: string } | null
  payer_user?: { id: string; name?: string } | null
  payment_method?: { id: string; name: string } | null
  payment_status?: { id: string; name: string } | null
  bank_account?: { id: string; name?: string } | null
  applications?: IPaymentApplication[]
}

/**
 * Form en PESOS; total se convierte a minor units al enviar.
 *
 * `charge_type` distingue los dos modos del sheet "Registrar pago":
 * - `pending` (default): pago sobre cargos existentes (FIFO/manual).
 * - `direct`: cobro directo de mostrador (POST /charge/direct) — genera un
 *   cargo puntual y su pago en una sola acción. En este modo el concepto es
 *   catálogo U "Otro" (texto libre), nunca ambos: `concept_mode` solo vive en
 *   el form (el submit decide qué campo mandar según su valor).
 */
export const registerPaymentSchema = z
  .object({
    unit_id: z.string().min(1, 'La unidad es requerida'),
    payer_user_id: z.string().optional(),
    paid_at: z.string().min(1, 'La fecha es requerida'),
    total_amount: z.number().positive('El monto debe ser mayor a 0'),
    payment_method_id: z.string().min(1, 'El método es requerido'),
    bank_account_id: z.string().min(1, 'La cuenta destino es requerida'),
    reference: z.string().max(255).optional(),
    physical_receipt_number: z.string().max(100).optional(),
    notes: z.string().optional(),
    application_mode: z.enum(['auto', 'manual']),
    charge_type: z.enum(['pending', 'direct']),
    concept_mode: z.enum(['catalog', 'other']),
    charge_concept_id: z.string().optional(),
    concept_free_text: z.string().max(255, 'Máximo 255 caracteres').optional(),
  })
  .superRefine((values, ctx) => {
    // El concepto solo es exigible en modo "Cobro directo".
    if (values.charge_type !== 'direct') return
    if (values.concept_mode === 'catalog' && !values.charge_concept_id) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['charge_concept_id'],
        message: 'El concepto es requerido',
      })
    }
    if (values.concept_mode === 'other' && !values.concept_free_text?.trim()) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['concept_free_text'],
        message: 'Describe el motivo del cobro',
      })
    }
  })

export type RegisterPaymentValues = z.infer<typeof registerPaymentSchema>

/**
 * Auto-captura del residente (§8.5.2): monto en pesos; el comprobante (File) se
 * valida aparte. El residente NO captura la cuenta destino — el backend la
 * asigna por defecto a la cuenta primaria activa del clúster.
 */
export const submitPaymentSchema = z.object({
  paid_at: z.string().min(1, 'La fecha es requerida'),
  total_amount: z
    .number({ message: 'El monto es requerido' })
    .positive('El monto debe ser mayor a 0'),
  payment_method_id: z.string().min(1, 'El método es requerido'),
  reference: z.string().max(255).optional(),
  notes: z.string().max(2000).optional(),
})

export type SubmitPaymentValues = z.infer<typeof submitPaymentSchema>

export interface StatementResponse {
  unit_id: string
  charges: ICharge[]
  credit_balance: IMoney
  totals: {
    expected: IMoney
    discount: IMoney
    surcharge: IMoney
    paid: IMoney
    outstanding: IMoney
  }
}

export interface DelinquencyUnit {
  /** Null cuando la unidad del cargo fue soft-deleted (el backend lo declara nullable). */
  unit_id: string | null
  unit_number?: string | null
  /** Identificador legible de la unidad (incluye el tipo: "Casa 1", "Torre Norte · Piso 1 · Depto 101"). */
  unit_full_identifier?: string | null
  outstanding: IMoney
  oldest_due_date?: string | null
  charge_count: number
}

export interface DelinquencyResponse {
  units_with_debt: number
  total_outstanding: IMoney
  data: DelinquencyUnit[]
}

/**
 * Filtros server-side de GET /delinquency (y de su export xlsx — el archivo
 * respeta los filtros activos). Los montos viajan en MINOR UNITS (centavos):
 * la UI captura pesos y convierte con pesosToMinorUnits antes de enviar.
 */
export interface DelinquencyQueryParams {
  /** Texto combinado: unidad (número, tipo, torre) O nombre de residente activo. */
  search?: string
  charge_concept_ids?: string[]
  /** Antigüedad mínima de la deuda en meses (desde el vencimiento más antiguo). */
  min_months_overdue?: number
  /** Adeudo mínimo por unidad, en minor units. */
  min_amount?: number
  /** Adeudo máximo por unidad, en minor units. */
  max_amount?: number
}
