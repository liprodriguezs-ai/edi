import type {
  AppointmentCreatePayload,
  AppointmentReschedulePayload,
  IAppointment,
  IAppointmentSuggestion,
} from '@/modules/appointment/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

interface AppointmentActions {
  confirm: (_id: string) => Promise<IAppointment>
  arrive: (_id: string) => Promise<IAppointment>
  start: (_id: string) => Promise<IAppointment>
  reschedule: (_id: string, _payload: AppointmentReschedulePayload) => Promise<IAppointment>
  cancel: (_id: string, _reason: string) => Promise<IAppointment>
  noShow: (_id: string) => Promise<IAppointment>
  walkIn: (_payload: Record<string, unknown>) => Promise<IAppointment>
  markDayNoShow: (_date: string, _healthCenterId?: string) => Promise<{ updated: number; message: string }>
  suggestion: (_childId: string) => Promise<IAppointmentSuggestion>
}

export const AppointmentResource = createCrudApi<
  IAppointment,
  AppointmentCreatePayload,
  Record<string, unknown>,
  AppointmentActions
>({
  basePath: '/appointment',
  customMethods: (basePath) => {
    const action = async (id: string, verb: string, body?: Record<string, unknown>) => {
      const { data } = await apiClient.post<{ data: IAppointment }>(`${basePath}/${id}/${verb}`, body ?? {})
      return data.data
    }

    return {
      confirm: (id) => action(id, 'confirm'),
      arrive: (id) => action(id, 'arrive'),
      start: (id) => action(id, 'start'),
      reschedule: (id, payload) => action(id, 'reschedule', { ...payload }),
      cancel: (id, reason) => action(id, 'cancel', { reason }),
      noShow: (id) => action(id, 'no_show'),
      walkIn: async (payload) => {
        const { data } = await apiClient.post<{ data: IAppointment }>(`${basePath}/walk_in`, payload)
        return data.data
      },
      markDayNoShow: async (date, healthCenterId) => {
        const { data } = await apiClient.post<{ data: { updated: number }; message: string }>(
          `${basePath}/mark_no_show`,
          { date, health_center_id: healthCenterId }
        )
        return { updated: data.data.updated, message: data.message }
      },
      suggestion: async (childId) => {
        const { data } = await apiClient.get<{ data: IAppointmentSuggestion }>(`${basePath}/suggestion`, {
          params: { child_id: childId },
        })
        return data.data
      },
    }
  },
})
