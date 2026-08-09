import type {
  DelinquencyQueryParams,
  DelinquencyResponse,
  IPayment,
  StatementResponse,
} from '@/modules/payment/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export interface RegisterPaymentPayload {
  unit_id: string
  paid_at: string
  /** minor units */
  total_amount: number
  payment_method_id: string
  bank_account_id: string
  payer_user_id?: string
  reference?: string
  physical_receipt_number?: string
  notes?: string
  receipt?: File | null
  manual_applications?: Array<{ charge_id: string; apply_amount: number }>
  earmark_type?: string
  earmark_id?: string
}

function toFormData(payload: RegisterPaymentPayload): FormData {
  const formData = new FormData()
  formData.append('unit_id', payload.unit_id)
  formData.append('paid_at', payload.paid_at)
  formData.append('total_amount', String(payload.total_amount))
  formData.append('payment_method_id', payload.payment_method_id)
  formData.append('bank_account_id', payload.bank_account_id)
  if (payload.payer_user_id) formData.append('payer_user_id', payload.payer_user_id)
  if (payload.reference) formData.append('reference', payload.reference)
  if (payload.physical_receipt_number)
    formData.append('physical_receipt_number', payload.physical_receipt_number)
  if (payload.notes) formData.append('notes', payload.notes)
  if (payload.receipt) formData.append('receipt', payload.receipt)
  if (payload.earmark_type) formData.append('earmark_type', payload.earmark_type)
  if (payload.earmark_id) formData.append('earmark_id', payload.earmark_id)
  payload.manual_applications?.forEach((app, index) => {
    formData.append(`manual_applications[${index}][charge_id]`, app.charge_id)
    formData.append(`manual_applications[${index}][apply_amount]`, String(app.apply_amount))
  })
  return formData
}

export interface SubmitPaymentPayload {
  unit_id: string
  paid_at: string
  /** minor units */
  total_amount: number
  payment_method_id: string
  reference?: string
  notes?: string
  receipt?: File | null
  earmark_type?: string
  earmark_id?: string
  /**
   * Optional destination account. The resident self-capture flow normally omits
   * this — the backend defaults it to the cluster's primary active account — but
   * an earmarked submission (e.g. an amenity reservation) REQUIRES the earmark's
   * own destination account (the amenity's configured `bank_account_id`).
   */
  bank_account_id?: string
}

function toSubmitFormData(payload: SubmitPaymentPayload): FormData {
  const formData = new FormData()
  formData.append('unit_id', payload.unit_id)
  formData.append('paid_at', payload.paid_at)
  formData.append('total_amount', String(payload.total_amount))
  formData.append('payment_method_id', payload.payment_method_id)
  if (payload.reference) formData.append('reference', payload.reference)
  if (payload.notes) formData.append('notes', payload.notes)
  if (payload.receipt) formData.append('receipt', payload.receipt)
  if (payload.earmark_type) formData.append('earmark_type', payload.earmark_type)
  if (payload.earmark_id) formData.append('earmark_id', payload.earmark_id)
  if (payload.bank_account_id) formData.append('bank_account_id', payload.bank_account_id)
  return formData
}

export const PaymentResource = createCrudApi<
  IPayment,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    register: (_payload: RegisterPaymentPayload) => Promise<IPayment>
    submit: (_payload: SubmitPaymentPayload) => Promise<IPayment>
    validatePayment: (_id: string) => Promise<IPayment>
    reject: (_id: string, _reason: string) => Promise<IPayment>
    cancelPayment: (_id: string, _reason: string) => Promise<IPayment>
    statement: (_unitId: string) => Promise<StatementResponse>
    delinquency: (_params?: DelinquencyQueryParams) => Promise<DelinquencyResponse>
    delinquencyReport: (_params?: DelinquencyQueryParams) => Promise<Blob>
  }
>({
  basePath: '/payment',
  customMethods: () => ({
    register: async (payload) => {
      const { data } = await apiClient.post<{ data: IPayment }>(
        '/payment/register',
        toFormData(payload)
      )
      return data.data
    },
    submit: async (payload) => {
      const { data } = await apiClient.post<{ data: IPayment }>(
        '/payment/submit',
        toSubmitFormData(payload)
      )
      return data.data
    },
    validatePayment: async (id) => {
      const { data } = await apiClient.post<{ data: IPayment }>(`/payment/${id}/validate`)
      return data.data
    },
    reject: async (id, reason) => {
      const { data } = await apiClient.post<{ data: IPayment }>(`/payment/${id}/reject`, { reason })
      return data.data
    },
    cancelPayment: async (id, reason) => {
      const { data } = await apiClient.post<{ data: IPayment }>(`/payment/${id}/cancel`, { reason })
      return data.data
    },
    statement: async (unitId) => {
      const { data } = await apiClient.get<StatementResponse>(`/unit/${unitId}/statement`)
      return data
    },
    delinquency: async (params) => {
      const { data } = await apiClient.get<DelinquencyResponse>('/delinquency', { params })
      return data
    },
    delinquencyReport: async (params) => {
      const { data } = await apiClient.get<Blob>('/delinquency/report', {
        responseType: 'blob',
        params,
      })
      return data
    },
  }),
})
