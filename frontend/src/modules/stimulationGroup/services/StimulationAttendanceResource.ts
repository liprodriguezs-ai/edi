import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import type { IEntity } from '@/shared/interfaces/Entity'

export interface IStimulationAttendance extends IEntity {
  stimulation_group_id: string
  child_id: string
  session_date: string
  attended: boolean
  homework_done: boolean
  attendance_payment_status_id: string
}

export interface AttendanceEntry {
  child_id: string
  attended: boolean
  homework_done: boolean
  attendance_payment_status_id?: string
}

export const StimulationAttendanceResource = createCrudApi<
  IStimulationAttendance,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    // REQ-SA-001 — bulk idempotent upsert; a single-entry array is a legal
    // (and here, the actual) call shape.
    upsert: (
      _groupId: string,
      _sessionDate: string,
      _entries: AttendanceEntry[]
    ) => Promise<IStimulationAttendance[]>
  }
>({
  basePath: '/stimulation_attendance',
  customMethods: () => ({
    upsert: async (groupId, sessionDate, entries) => {
      const { data } = await apiClient.post<{ data: IStimulationAttendance[] }>(
        `/stimulation_group/${groupId}/attendance`,
        { session_date: sessionDate, entries }
      )
      return data.data
    },
  }),
})
