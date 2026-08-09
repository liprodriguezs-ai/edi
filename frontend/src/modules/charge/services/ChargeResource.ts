import type { ICharge } from '@/modules/charge/schema'
import type { IPayment } from '@/modules/payment/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

/** Payload de POST /charge/direct (Fase 1 "Cobro directo / cargo puntual"). */
export interface DirectChargePayload {
  unit_id: string
  /** Requerido salvo que se envíe `concept_free_text` (motivo "Otro"). */
  charge_concept_id?: string
  /** Motivo libre ("Otro"). Si viene, se omite `charge_concept_id`. */
  concept_free_text?: string
  /** minor units */
  expected_amount: number
  payment_method_id: string
  bank_account_id: string
  paid_at?: string
  reference?: string
  physical_receipt_number?: string
  notes?: string
  receipt?: File | null
}

/** Respuesta de POST /charge/direct: crea el cargo Y el pago en una sola acción atómica. */
export interface DirectChargeResult {
  charge: ICharge
  payment: IPayment
}

function toDirectChargeFormData(payload: DirectChargePayload): FormData {
  const formData = new FormData()
  formData.append('unit_id', payload.unit_id)
  if (payload.charge_concept_id) formData.append('charge_concept_id', payload.charge_concept_id)
  if (payload.concept_free_text) formData.append('concept_free_text', payload.concept_free_text)
  formData.append('expected_amount', String(payload.expected_amount))
  formData.append('payment_method_id', payload.payment_method_id)
  formData.append('bank_account_id', payload.bank_account_id)
  if (payload.paid_at) formData.append('paid_at', payload.paid_at)
  if (payload.reference) formData.append('reference', payload.reference)
  if (payload.physical_receipt_number)
    formData.append('physical_receipt_number', payload.physical_receipt_number)
  if (payload.notes) formData.append('notes', payload.notes)
  if (payload.receipt) formData.append('receipt', payload.receipt)
  return formData
}

/** Sin comprobante (ej. efectivo) el contrato pide JSON plano, no multipart. */
function toDirectChargeJson(payload: DirectChargePayload): Record<string, unknown> {
  return {
    unit_id: payload.unit_id,
    charge_concept_id: payload.charge_concept_id,
    concept_free_text: payload.concept_free_text,
    expected_amount: payload.expected_amount,
    payment_method_id: payload.payment_method_id,
    bank_account_id: payload.bank_account_id,
    paid_at: payload.paid_at,
    reference: payload.reference,
    physical_receipt_number: payload.physical_receipt_number,
    notes: payload.notes,
  }
}

export const ChargeResource = createCrudApi<
  ICharge,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    cancel: (_id: string, _reason: string) => Promise<ICharge>
    discount: (_id: string, _payload: { amount: number; reason: string }) => Promise<ICharge>
    directCharge: (_payload: DirectChargePayload) => Promise<DirectChargeResult>
  }
>({
  basePath: '/charge',
  customMethods: () => ({
    cancel: async (id, reason) => {
      const { data } = await apiClient.post<{ data: ICharge }>(`/charge/${id}/cancel`, { reason })
      return data.data
    },
    discount: async (id, payload) => {
      const { data } = await apiClient.post<{ data: ICharge }>(`/charge/${id}/discount`, payload)
      return data.data
    },
    directCharge: async (payload) => {
      // multipart/form-data cuando hay comprobante adjunto; JSON plano si no (contrato del endpoint).
      const body = payload.receipt ? toDirectChargeFormData(payload) : toDirectChargeJson(payload)
      const { data } = await apiClient.post<{ data: DirectChargeResult }>('/charge/direct', body)
      return data.data
    },
  }),
})
