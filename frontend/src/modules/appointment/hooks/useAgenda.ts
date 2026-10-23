import type { AppointmentReschedulePayload, IAppointment } from '@/modules/appointment/schema'
import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { toast } from '@/shared/hooks/useToast'
import type { PaginatedResponse } from '@/shared/interfaces/Entity'
import type { Filter } from '@/shared/interfaces/list.types'
import { tenantCivilRangeToUtc, tenantToday } from '@/shared/utils/tenantTime'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'

const AGENDA_KEY = ['appointment'] as const

/** Máximo que acepta Orion por página. */
const AGENDA_PAGE_LIMIT = 100

const INCLUDES = [
  { relation: 'child' },
  { relation: 'practitioner' },
  { relation: 'practitioner.user' },
  { relation: 'appointment_type' },
  { relation: 'appointment_status' },
  { relation: 'visit' },
]

/** Tope de páginas por rango: 10 × 100 citas cubre de sobra un mes de un centro. */
const MAX_PAGES = 10

/** Junta todas las páginas de una búsqueda (hasta `MAX_PAGES`). */
export async function collectPages<T>(fetchPage: (_page: number) => Promise<PaginatedResponse<T>>): Promise<T[]> {
  const items: T[] = []
  for (let page = 1; page <= MAX_PAGES; page++) {
    const response = await fetchPage(page)
    items.push(...response.data)
    const lastPage = response.meta?.last_page ?? page
    if (page >= lastPage) break
  }
  return items
}

async function searchRange(start: string, end: string, healthCenterId?: string, sortField = 'starts_at') {
  const filters: Filter[] = [
    { field: 'starts_at', operator: '>=', value: start },
    { field: 'starts_at', operator: '<', value: end },
  ]
  if (healthCenterId) filters.push({ field: 'health_center_id', operator: '=', value: healthCenterId })

  return collectPages((page) =>
    AppointmentResource.search({
      filters,
      sort: [{ field: sortField, direction: 'asc' }],
      includes: INCLUDES,
      limit: AGENDA_PAGE_LIMIT,
      page,
    })
  )
}

/** Citas de un rango de días civiles del tenant (`from`..`to`, ambos inclusivos). */
export function useAgendaAppointments(from: string, to: string, healthCenterId?: string, enabled = true) {
  const timeZone = useTenantTimeZone()
  return useQuery({
    enabled,
    queryKey: [...AGENDA_KEY, 'range', from, to, healthCenterId, timeZone],
    queryFn: () => {
      const { start, end } = tenantCivilRangeToUtc(from, to, timeZone)
      return searchRange(start, end, healthCenterId)
    },
  })
}

/** Sala de espera: citas de HOY que ya llegaron, en orden de llegada. */
export function useWaitingRoom(healthCenterId?: string, enabled = true) {
  const timeZone = useTenantTimeZone()
  const today = tenantToday(timeZone)
  return useQuery({
    enabled,
    queryKey: [...AGENDA_KEY, 'waiting-room', today, healthCenterId, timeZone],
    queryFn: async () => {
      const { start, end } = tenantCivilRangeToUtc(today, today, timeZone)
      const appointments = await searchRange(start, end, healthCenterId, 'arrived_at')
      return appointments.filter((appointment) => appointment.appointment_status?.key === 'arrived')
    },
    refetchInterval: 60_000,
  })
}

function notifyError(error: unknown, fallback: string) {
  if (error instanceof ApiError) {
    const { title, description } = formatApiErrorMessage(error)
    toast.error(title, description)
    return
  }
  toast.error(fallback)
}

function useAppointmentMutation<TArgs>(
  fn: (_args: TArgs) => Promise<IAppointment>,
  success: string,
  fallback: string,
  onDone: () => void
) {
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      toast.success(success)
      onDone()
    },
    onError: (error) => notifyError(error, fallback),
  })
}

/**
 * Transiciones de la cita. Cada éxito refresca la agenda y la sala de espera;
 * "Iniciar atención" abre la captura del EDI cuando la cita es de tamizaje.
 */
export function useAppointmentActions() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: AGENDA_KEY })
  }

  const confirm = useAppointmentMutation(
    (id: string) => AppointmentResource.confirm(id),
    'Cita confirmada',
    'No se pudo confirmar la cita',
    refresh
  )
  const arrive = useAppointmentMutation(
    (id: string) => AppointmentResource.arrive(id),
    'Llegada registrada',
    'No se pudo registrar la llegada',
    refresh
  )
  const noShow = useAppointmentMutation(
    (id: string) => AppointmentResource.noShow(id),
    'Cita marcada como no asistió',
    'No se pudo actualizar la cita',
    refresh
  )
  const cancel = useAppointmentMutation(
    ({ id, reason }: { id: string; reason: string }) => AppointmentResource.cancel(id, reason),
    'Cita cancelada',
    'No se pudo cancelar la cita',
    refresh
  )
  const reschedule = useAppointmentMutation(
    ({ id, payload }: { id: string; payload: AppointmentReschedulePayload }) =>
      AppointmentResource.reschedule(id, payload),
    'Cita reprogramada',
    'No se pudo reprogramar la cita',
    refresh
  )

  const start = useMutation({
    mutationFn: (id: string) => AppointmentResource.start(id),
    onSuccess: (appointment) => {
      toast.success('Atención iniciada', appointment.visit?.receipt_number ? `Folio ${appointment.visit.receipt_number}` : undefined)
      refresh()
      if (appointment.appointment_type?.key === 'screening' && appointment.visit_id) {
        navigate(`/screening/apply/${appointment.visit_id}`)
        return
      }
      navigate(`/child/${appointment.child_id}`)
    },
    onError: (error) => notifyError(error, 'No se pudo iniciar la atención'),
  })

  const closeDay = useMutation({
    mutationFn: ({ date, healthCenterId }: { date: string; healthCenterId?: string }) =>
      AppointmentResource.markDayNoShow(date, healthCenterId),
    onSuccess: (result) => {
      toast.success(result.message)
      refresh()
    },
    onError: (error) => notifyError(error, 'No se pudo cerrar el día'),
  })

  return { confirm, arrive, start, noShow, cancel, reschedule, closeDay, refresh }
}
