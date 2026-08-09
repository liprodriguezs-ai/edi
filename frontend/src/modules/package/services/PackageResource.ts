import type {
  IAgingReport,
  IMyPackage,
  IPendingCount,
  IPackage,
  IPickupPolicy,
  IVerifyCodeResult,
  PackageFormValues,
} from '@/modules/package/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const PackageResource = createCrudApi<
  IPackage,
  PackageFormValues,
  Record<string, unknown>,
  {
    pickupPolicy: () => Promise<IPickupPolicy>
    verifyCode: (_code: string) => Promise<IVerifyCodeResult>
    handover: (_payload: FormData) => Promise<IPackage[]>
    returnPackage: (_id: string, _reason?: string) => Promise<IPackage>
    incident: (_id: string, _reason: string) => Promise<IPackage>
    reassign: (_id: string, _unitId: string) => Promise<IPackage>
    batch: (_payload: FormData | { packages: unknown[] }) => Promise<IPackage[]>
    myPackages: (_params?: { history?: number }) => Promise<IMyPackage[]>
    aging: () => Promise<IAgingReport>
    agingExport: () => Promise<Blob>
    pendingCount: () => Promise<IPendingCount>
  }
>({
  basePath: '/package',
  customMethods: () => ({
    /**
     * GET /package/pickup-policy — lightweight endpoint that guards (update.package)
     * can call without read.configuration. Returns only the two handover-relevant flags.
     */
    pickupPolicy: async () => {
      const { data } = await apiClient.get<IPickupPolicy>('/package/pickup-policy')
      return data
    },
    verifyCode: async (code) => {
      // Backend reads `pickup_code` (matches the column); sending `code` 422s.
      const { data } = await apiClient.post<IVerifyCodeResult>('/package/verify-code', {
        pickup_code: code,
      })
      return data
    },
    handover: async (payload) => {
      const { data } = await apiClient.post<{ data: IPackage[] }>('/package/handover', payload)
      return data.data
    },
    returnPackage: async (id, reason) => {
      const { data } = await apiClient.post<{ data: IPackage }>(
        `/package/${id}/return`,
        reason ? { reason } : {},
      )
      return data.data
    },
    incident: async (id, reason) => {
      const { data } = await apiClient.post<{ data: IPackage }>(`/package/${id}/incident`, { reason })
      return data.data
    },
    reassign: async (id, unitId) => {
      const { data } = await apiClient.post<{ data: IPackage }>(`/package/${id}/reassign`, {
        unit_id: unitId,
      })
      return data.data
    },
    /**
     * POST /package/intake-batch — bulk registration.
     * The endpoint name avoids collision with Orion's own POST /package/batch.
     */
    batch: async (payload) => {
      const { data } = await apiClient.post<{ data: IPackage[] }>('/package/intake-batch', payload)
      return data.data
    },
    myPackages: async (params) => {
      const { data } = await apiClient.get<{ data: IMyPackage[] }>('/my-packages', { params })
      return data.data
    },
    aging: async () => {
      const { data } = await apiClient.get<IAgingReport>('/package/report/aging')
      return data
    },
    /**
     * GET /package/report/aging/export — dedicated xlsx export (WU-5).
     * Does NOT use the generic report()/legacy POST /customReport path, which has
     * no 'package' case and would return garbage.
     */
    agingExport: async () => {
      const { data } = await apiClient.get<Blob>('/package/report/aging/export', {
        responseType: 'blob',
      })
      return data
    },
    pendingCount: async () => {
      const { data } = await apiClient.get<IPendingCount>('/package/report/pending-count')
      return data
    },
  }),
})
