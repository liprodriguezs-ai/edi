import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { childFullName } from '@/modules/child/schema'
import { type IStimulationGroup } from '@/modules/stimulationGroup/schema'
import { StimulationGroupResource } from '@/modules/stimulationGroup/services/StimulationGroupResource'
import { StimulationGroupMemberResource } from '@/modules/stimulationGroup/services/StimulationGroupMemberResource'
import {
  StimulationAttendanceResource,
  type IStimulationAttendance,
} from '@/modules/stimulationGroup/services/StimulationAttendanceResource'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { Skeleton } from 'boneyard-js/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { todayLocalDateString } from '@/shared/utils/dateHelper'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'

interface AttendanceRowState {
  attended: boolean
  homework_done: boolean
  attendance_payment_status_id: string
}

interface MemberRow {
  child_id: string
  name: string
  defaultStatusId: string
}

/**
 * REQ-XC-005 / REQ-SA-001 — hoja de asistencia: fecha (default hoy), fila por
 * miembro ACTIVO, tres controles por fila. Cambios se aplican de forma
 * optimista y se persisten con el upsert masivo; si falla, se revierte y se
 * muestra un toast de error. Catálogos cargan de forma perezosa.
 */
export function AttendanceSheetPage() {
  const { id } = useParams<{ id: string }>()

  const [group, setGroup] = useState<IStimulationGroup | null>(null)
  const [members, setMembers] = useState<MemberRow[]>([])
  const [rows, setRows] = useState<Record<string, AttendanceRowState>>({})
  const [savingChildIds, setSavingChildIds] = useState<Record<string, boolean>>({})
  const [sessionDate, setSessionDate] = useState<string>(todayLocalDateString())
  const [isLoading, setIsLoading] = useState(true)

  const { catalogs, isLoading: isLoadingStatuses, enable: enableStatuses } = useLazyCatalogs({
    [CatalogType.ATTENDANCE_PAYMENT_STATUS]: { _sort: 'name' },
  })
  const statusOptions = catalogs[CatalogType.ATTENDANCE_PAYMENT_STATUS] ?? []

  const buildDefaultRows = useCallback(
    (memberRows: MemberRow[], attendance: IStimulationAttendance[]): Record<string, AttendanceRowState> => {
      const byChild = new Map(attendance.map((a) => [a.child_id, a]))
      const next: Record<string, AttendanceRowState> = {}
      for (const member of memberRows) {
        const existing = byChild.get(member.child_id)
        next[member.child_id] = existing
          ? {
              attended: existing.attended,
              homework_done: existing.homework_done,
              attendance_payment_status_id: existing.attendance_payment_status_id,
            }
          : { attended: false, homework_done: false, attendance_payment_status_id: member.defaultStatusId }
      }
      return next
    },
    []
  )

  const loadSheet = useCallback(
    async (date: string) => {
      if (!id) return
      setIsLoading(true)
      enableStatuses()
      try {
        const [groupData, memberResponse] = await Promise.all([
          StimulationGroupResource.show(id),
          StimulationGroupMemberResource.search({
            filters: [{ field: 'stimulation_group_id', operator: '=', value: id }],
            includes: [{ relation: 'child' }, { relation: 'default_attendance_payment_status' }],
          }),
        ])
        setGroup(groupData)

        const memberRows: MemberRow[] = memberResponse.data.map((m) => ({
          child_id: m.child_id,
          name: m.child ? childFullName(m.child) : '—',
          defaultStatusId: m.default_attendance_payment_status_id,
        }))
        setMembers(memberRows)

        const attendanceResponse = await StimulationAttendanceResource.search({
          filters: [
            { field: 'stimulation_group_id', operator: '=', value: id },
            { field: 'session_date', operator: '=', value: date },
          ],
        })
        setRows(buildDefaultRows(memberRows, attendanceResponse.data))
      } catch {
        toast.error('No se pudo cargar la hoja de asistencia')
      } finally {
        setIsLoading(false)
      }
    },
    [id, enableStatuses, buildDefaultRows]
  )

  useEffect(() => {
    void loadSheet(sessionDate)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, sessionDate])

  const persistEntry = useCallback(
    async (childId: string, previous: AttendanceRowState, next: AttendanceRowState) => {
      if (!id) return
      setSavingChildIds((prev) => ({ ...prev, [childId]: true }))
      try {
        await StimulationAttendanceResource.upsert(id, sessionDate, [
          {
            child_id: childId,
            attended: next.attended,
            homework_done: next.homework_done,
            attendance_payment_status_id: next.attendance_payment_status_id,
          },
        ])
      } catch {
        // Revert on failure — optimistic update is undone.
        setRows((prev) => ({ ...prev, [childId]: previous }))
        toast.error('No se pudo guardar la asistencia. Se revirtió el cambio.')
      } finally {
        setSavingChildIds((prev) => ({ ...prev, [childId]: false }))
      }
    },
    [id, sessionDate]
  )

  const updateRow = useCallback(
    (childId: string, patch: Partial<AttendanceRowState>) => {
      const previous = rows[childId]
      if (!previous) return
      const next = { ...previous, ...patch }
      setRows((prev) => ({ ...prev, [childId]: next }))
      void persistEntry(childId, previous, next)
    },
    [rows, persistEntry]
  )

  const isFuture = useMemo(() => sessionDate > todayLocalDateString(), [sessionDate])

  if (!id) return <Navigate to="/stimulation-group" replace />

  return (
    <div className="space-y-6">
      <PageHeader
        title={group ? `Asistencia — ${group.name}` : 'Asistencia'}
        description="Registra la asistencia, tarea y estatus de pago del día"
      />

      <div className="max-w-xs">
        <Input
          type="date"
          className="font-mono tabular-nums"
          value={sessionDate}
          max={todayLocalDateString()}
          onChange={(e) => setSessionDate(e.target.value)}
        />
        {isFuture && <p className="mt-1 text-xs text-destructive">La fecha no puede ser futura.</p>}
      </div>

      <Skeleton name="attendance-sheet" loading={isLoading}>
        {members.length === 0 ? (
          <InlineEmpty>Este grupo no tiene miembros activos.</InlineEmpty>
        ) : (
          <div className="overflow-x-auto">
            <Table className="border border-border [&_td]:border-r [&_td]:border-border [&_td:last-child]:border-r-0 [&_th]:border-r [&_th]:border-border [&_th:last-child]:border-r-0">
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Niño</TableHead>
                  <TableHead scope="col" className="text-center">
                    Asistió
                  </TableHead>
                  <TableHead scope="col" className="text-center">
                    Tarea
                  </TableHead>
                  <TableHead scope="col">Estatus de pago</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {members.map((member) => {
                  const row = rows[member.child_id]
                  if (!row) return null
                  const isSaving = Boolean(savingChildIds[member.child_id])
                  return (
                    <TableRow key={member.child_id}>
                      <TableCell className="font-medium">{member.name}</TableCell>
                      <TableCell className="text-center">
                        <Checkbox
                          checked={row.attended}
                          onCheckedChange={(checked) => updateRow(member.child_id, { attended: Boolean(checked) })}
                          disabled={isSaving}
                          aria-label={`Asistió — ${member.name}`}
                        />
                      </TableCell>
                      <TableCell className="text-center">
                        <Checkbox
                          checked={row.homework_done}
                          onCheckedChange={(checked) =>
                            updateRow(member.child_id, { homework_done: Boolean(checked) })
                          }
                          disabled={isSaving}
                          aria-label={`Tarea — ${member.name}`}
                        />
                      </TableCell>
                      <TableCell className="min-w-[180px]">
                        <select
                          className="h-8 w-full rounded-none border border-input bg-transparent px-2 text-[13px] text-[var(--ink-2)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                          value={row.attendance_payment_status_id}
                          onChange={(e) =>
                            updateRow(member.child_id, { attendance_payment_status_id: e.target.value })
                          }
                          disabled={isSaving || isLoadingStatuses}
                          aria-label={`Estatus de pago — ${member.name}`}
                        >
                          {statusOptions.map((option) => (
                            <option key={option.id} value={option.id}>
                              {option.name}
                            </option>
                          ))}
                        </select>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        )}
      </Skeleton>
    </div>
  )
}
