import type { IChargeBatch } from '@/modules/chargeBatch/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import type { IMoney } from '@/shared/utils/money'

/**
 * Resumen agregado de un lote de cobros calculado por el servidor.
 * Los campos monetarios (expected, paid, outstanding, partial_paid,
 * unpaid_outstanding) llegan serializados como Money. Los demás son conteos.
 */
export interface ChargeBatchSummary {
  expected: IMoney
  paid: IMoney
  outstanding: IMoney
  partial_paid: IMoney
  unpaid_outstanding: IMoney
  total: number
  completed: number
  partial: number
  unpaid: number
  overdue: number
}

export const ChargeBatchResource = createCrudApi<
  IChargeBatch,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    preview: (_payload: {
      target_type: string
      target_selector?: Record<string, unknown> | null
    }) => Promise<number>
    activate: (_id: string) => Promise<IChargeBatch>
    cancelBatch: (_id: string, _reason?: string) => Promise<IChargeBatch>
    summary: (_id: string) => Promise<ChargeBatchSummary>
  }
>({
  basePath: '/charge_batch',
  customMethods: () => ({
    preview: async (payload) => {
      const { data } = await apiClient.post<{ count: number }>('/charge-batch/preview', payload)
      return data.count
    },
    activate: async (id) => {
      const { data } = await apiClient.post<{ data: IChargeBatch }>(`/charge-batch/${id}/activate`)
      return data.data
    },
    cancelBatch: async (id, reason) => {
      const { data } = await apiClient.post<{ data: IChargeBatch }>(`/charge-batch/${id}/cancel`, {
        reason,
      })
      return data.data
    },
    summary: async (id) => {
      const { data } = await apiClient.get<{ data: ChargeBatchSummary }>(
        `/charge-batch/${id}/summary`
      )
      return data.data
    },
  }),
})
