import type { EntryRequestValues, IAccessLog, IBlacklistEntry, IMyQr, IScanResult, IVisitor, ManualEntryValues, VisitorFormValues } from '@/modules/access/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const VisitorResource = createCrudApi<IVisitor, VisitorFormValues, Record<string, unknown>, {
  approve: (_id: string) => Promise<unknown>
  reject: (_id: string) => Promise<unknown>
  cancelQr: (_id: string) => Promise<unknown>
}>({
  basePath: '/visitor',
  customMethods: (basePath) => ({
    approve: async (id) => (await apiClient.post(`${basePath}/${id}/approve`)).data,
    reject: async (id) => (await apiClient.post(`${basePath}/${id}/reject`)).data,
    cancelQr: async (id) => (await apiClient.post(`${basePath}/${id}/cancel`)).data,
  }),
})

export const AccessLogResource = createCrudApi<IAccessLog, Record<string, unknown>, Record<string, unknown>, {
  now: () => Promise<IAccessLog[]>
  manualEntry: (_payload: ManualEntryValues, _photo?: File) => Promise<IAccessLog>
  entryRequest: (_payload: EntryRequestValues) => Promise<IVisitor>
  checkout: (_id: string) => Promise<IAccessLog>
  addCorrection: (_id: string, _note: string) => Promise<IAccessLog>
  scanQr: (_token: string) => Promise<IScanResult>
  myLog: (_page?: number) => Promise<{ data: IAccessLog[]; current_page: number; last_page: number; total: number }>
  myQr: () => Promise<IMyQr>
}>({
  basePath: '/access_log',
  customMethods: () => ({
    now: async () => {
      const { data } = await apiClient.get<{ data: IAccessLog[] }>('/access/now')
      return data.data
    },
    entryRequest: async (payload) => {
      // El residente aprueba/rechaza después; 422 si la visita está en lista negra.
      const { data } = await apiClient.post<{ data: IVisitor }>('/access/entry-request', payload)
      return data.data
    },
    myLog: async (page = 1) => {
      const { data } = await apiClient.get<{ data: IAccessLog[]; current_page: number; last_page: number; total: number }>('/access/my-log', { params: { page } })
      return data
    },
    myQr: async () => {
      // El countdown (seconds_remaining) lo calcula el servidor; el front nunca
      // deriva la vigencia del reloj local. El token rota cada 60s en el backend.
      const { data } = await apiClient.get<{ data: IMyQr }>('/access/my-qr')
      return data.data
    },
    scanQr: async (token) => {
      // 200 => { valid:true, ... }; 422 => lanza ApiError con payload = IScanResult (reason).
      const { data } = await apiClient.post<IScanResult>('/access/scan-qr', { token })
      return data
    },
    manualEntry: async (payload, photo) => {
      if (photo) {
        const form = new FormData()
        const entries = Object.entries(payload) as [string, unknown][]
        for (const [key, value] of entries) {
          if (value !== undefined && value !== null) {
            form.append(key, String(value))
          }
        }
        form.append('photo', photo)
        const { data } = await apiClient.post<{ data: IAccessLog }>('/access/manual-entry', form)
        return data.data
      }
      const { data } = await apiClient.post<{ data: IAccessLog }>('/access/manual-entry', payload)
      return data.data
    },
    checkout: async (id) => {
      const { data } = await apiClient.post<{ data: IAccessLog }>(`/access/${id}/checkout`)
      return data.data
    },
    addCorrection: async (id, note) => {
      const { data } = await apiClient.post<{ data: IAccessLog }>(`/access/${id}/correction`, { note })
      return data.data
    },
  }),
})

export const BlacklistResource = createCrudApi<IBlacklistEntry, Record<string, unknown>>({
  basePath: '/blacklist_entry',
})
