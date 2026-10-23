import type { IAppointment } from '@/modules/appointment/schema'
import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { appointmentStatusKey } from '@/modules/appointment/utils/appointmentStatus'
import type { IFollowUp } from '@/modules/followUp/schema'
import { FollowUpResource } from '@/modules/followUp/services/FollowUpResource'
import type { IScreening } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { canContinueApplication } from '@/modules/screening/utils/statusCopy'
import { useQuery } from '@tanstack/react-query'
import { Temporal } from 'temporal-polyfill'

/** Enough for a child's whole history (one test every few months up to 71 months). */
const SCREENINGS_LIMIT = 50

/** Every Prueba EDI of the child (the API loads all its relations). */
export function useChildScreenings(childId: string, enabled: boolean) {
  return useQuery({
    queryKey: ['screening', 'child', childId],
    enabled,
    queryFn: async (): Promise<IScreening[]> => {
      const response = await ScreeningResource.search({
        filters: [{ field: 'child_id', operator: '=', value: childId }],
        limit: SCREENINGS_LIMIT,
      })
      return response.data
    },
  })
}

/** Latest test that already has a result (newest visit first). */
export function lastScoredScreening(screenings: IScreening[]): IScreening | null {
  return (
    [...screenings]
      .filter((item) => item.result_color && item.screening_status?.key !== 'invalidated')
      .sort((a, b) => (b.visit?.visit_date ?? '').localeCompare(a.visit?.visit_date ?? ''))[0] ?? null
  )
}

/** Most recent test still open (draft or scored), to offer it before starting another. */
export function latestOpenScreening(screenings: IScreening[]): IScreening | null {
  return (
    [...screenings]
      .filter((item) => canContinueApplication(item.screening_status?.key))
      .sort((a, b) => (b.visit?.visit_date ?? '').localeCompare(a.visit?.visit_date ?? ''))[0] ?? null
  )
}

const UPCOMING: string[] = ['scheduled', 'confirmed', 'arrived']

/** First appointment from now on that is still going to happen. */
export function useChildNextAppointment(childId: string, enabled: boolean) {
  return useQuery({
    queryKey: ['appointment', 'child-next', childId],
    enabled,
    queryFn: async (): Promise<IAppointment | null> => {
      const response = await AppointmentResource.search({
        filters: [
          { field: 'child_id', operator: '=', value: childId },
          { field: 'starts_at', operator: '>=', value: Temporal.Now.instant().toString() },
        ],
        sort: [{ field: 'starts_at', direction: 'asc' }],
        includes: [{ relation: 'appointment_type' }, { relation: 'appointment_status' }, { relation: 'practitioner.user' }],
        limit: 10,
      })
      return response.data.find((item) => UPCOMING.includes(appointmentStatusKey(item))) ?? null
    },
  })
}

/** Every follow-up of the child (open and closed), by due date. */
export function useChildFollowUps(childId: string, enabled: boolean) {
  return useQuery({
    queryKey: ['follow-up', 'child', childId],
    enabled,
    queryFn: async (): Promise<IFollowUp[]> => {
      const response = await FollowUpResource.search({
        filters: [{ field: 'child_id', operator: '=', value: childId }],
        sort: [{ field: 'due_date', direction: 'asc' }],
        includes: [{ relation: 'follow_up_type' }, { relation: 'follow_up_status' }],
        limit: 50,
      })
      return response.data
    },
  })
}
