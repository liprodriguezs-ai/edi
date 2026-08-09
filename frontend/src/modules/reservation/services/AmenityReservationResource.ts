import type {
  IAmenityOccupancyEntry,
  IAmenityReservation,
  IReservationSurcharge,
} from '@/modules/reservation/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export interface AddSurchargePayload {
  charge_concept_id: string
  /** minor units */
  amount: number
  description?: string
}

/**
 * `/amenity_reservation` — Orion CRUD (list/show) + las 5 transiciones FSM
 * custom (S7) + el alta de cargos adicionales (S9). Las transiciones y el
 * alta de cargo NO llevan payload de aplicación de pagos: eso vive en
 * `PaymentResource` (register/submit con `earmark_type: 'amenity_reservation'`).
 */
export const AmenityReservationResource = createCrudApi<
  IAmenityReservation,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    approve: (_id: string) => Promise<IAmenityReservation>
    reject: (_id: string, _rejectedReason: string) => Promise<IAmenityReservation>
    cancel: (_id: string, _cancellationReason: string) => Promise<IAmenityReservation>
    complete: (_id: string) => Promise<IAmenityReservation>
    noShow: (_id: string) => Promise<IAmenityReservation>
    addSurcharge: (_id: string, _payload: AddSurchargePayload) => Promise<IReservationSurcharge>
    occupancy: (_from: string, _to: string) => Promise<IAmenityOccupancyEntry[]>
  }
>({
  basePath: '/amenity_reservation',
  customMethods: (basePath) => ({
    approve: async (id) => {
      const { data } = await apiClient.post<{ data: IAmenityReservation }>(
        `${basePath}/${id}/approve`
      )
      return data.data
    },
    reject: async (id, rejectedReason) => {
      const { data } = await apiClient.post<{ data: IAmenityReservation }>(
        `${basePath}/${id}/reject`,
        { rejected_reason: rejectedReason }
      )
      return data.data
    },
    cancel: async (id, cancellationReason) => {
      const { data } = await apiClient.post<{ data: IAmenityReservation }>(
        `${basePath}/${id}/cancel`,
        { cancellation_reason: cancellationReason }
      )
      return data.data
    },
    complete: async (id) => {
      const { data } = await apiClient.post<{ data: IAmenityReservation }>(
        `${basePath}/${id}/complete`
      )
      return data.data
    },
    noShow: async (id) => {
      const { data } = await apiClient.post<{ data: IAmenityReservation }>(
        `${basePath}/${id}/no-show`
      )
      return data.data
    },
    addSurcharge: async (id, payload) => {
      const { data } = await apiClient.post<{ data: IReservationSurcharge }>(
        `${basePath}/${id}/surcharges`,
        payload
      )
      return data.data
    },
    /**
     * S18 — `GET /amenity_reservation/occupancy?from=…&to=…` (rango ≤ 62 días).
     * Calendario de ocupación ANONIMIZADO para el comité de vigilancia: solo
     * identidad de amenidad + fecha/horario + occupied/capacity, nunca unidad,
     * residente ni id de reservación.
     */
    occupancy: async (from, to) => {
      const { data } = await apiClient.get<{ data: IAmenityOccupancyEntry[] }>(
        `${basePath}/occupancy`,
        { params: { from, to } }
      )
      return data.data
    },
  }),
})
