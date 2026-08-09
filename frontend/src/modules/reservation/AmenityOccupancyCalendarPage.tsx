// Ver `reservationCalendarMapping.ts` — este side-effect import expone `Temporal` como
// GLOBAL en tiempo de ejecución (requerido por `@schedule-x/calendar`) y trae la
// declaración ambient de tipos que usa `useCalendarApp`/`ScheduleXCalendar`.
import 'temporal-polyfill/global'
import '@schedule-x/theme-default/dist/index.css'

import {
  AMENITY_CALENDARS,
  mapOccupancyEntryToCalendarEvent,
} from '@/modules/reservation/reservationCalendarMapping'
import type { IAmenityOccupancyEntry } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { createViewDay, createViewMonthGrid, createViewWeek } from '@schedule-x/calendar'
import { ScheduleXCalendar, useCalendarApp } from '@schedule-x/react'
import { useQuery } from '@tanstack/react-query'
import { addDays, endOfMonth, format, startOfMonth } from 'date-fns'
import { useEffect, useMemo, useState } from 'react'

interface VisibleRange {
  start: string
  end: string
}

/**
 * Rango inicial ANTES de que schedule-x calcule su rango real de la vista por
 * defecto (mes): mes actual con 7 días de margen a cada lado — misma técnica
 * que `AmenityReservationCalendarPage`, y siempre muy por debajo del tope de
 * 62 días que acepta el endpoint (`AmenityOccupancyService::MAX_RANGE_DAYS`).
 */
function initialVisibleRange(): VisibleRange {
  const now = new Date()
  return {
    start: format(addDays(startOfMonth(now), -7), 'yyyy-MM-dd'),
    end: format(addDays(endOfMonth(now), 7), 'yyyy-MM-dd'),
  }
}

/**
 * Calendario de OCUPACIÓN anonimizado (S18) para el comité de vigilancia:
 * transparencia sobre qué amenidad/horario está ocupado en TODO el clúster,
 * SIN ningún dato personal — ni unidad, ni residente, ni id de reservación
 * (ver `AmenityOccupancyService` en el backend, que solo selecciona
 * amenity_id/amenity_name/reservation_date/slot_id/start_at/end_at/occupied/
 * capacity). El fetch está acotado al rango VISIBLE del calendario (se
 * re-consulta al navegar, nunca trae todo el historial — mismo criterio de
 * costo de infra que `AmenityReservationCalendarPage`).
 *
 * A propósito NO hay click-to-detail: no hay ningún dato sensible detrás de
 * un evento que mostrar, así que `onEventClick` se omite y el click queda
 * inerte.
 */
export const AmenityOccupancyCalendarPage = () => {
  const [visibleRange, setVisibleRange] = useState<VisibleRange>(initialVisibleRange)

  const { data, isLoading } = useQuery({
    queryKey: ['amenity-reservation', 'occupancy', visibleRange.start, visibleRange.end],
    queryFn: () => AmenityReservationResource.occupancy(visibleRange.start, visibleRange.end),
    staleTime: 30 * 1000,
  })

  const entries = useMemo<IAmenityOccupancyEntry[]>(() => data ?? [], [data])

  const monthView = useMemo(() => createViewMonthGrid(), [])

  // El config queda CONGELADO al primer render (mismo comportamiento de
  // `useCalendarApp` documentado en AmenityReservationCalendarPage): solo
  // `setVisibleRange` (estable, de useState) puede referenciarse en callbacks.
  const calendarApp = useCalendarApp({
    locale: 'es-ES',
    views: [monthView, createViewWeek(), createViewDay()],
    defaultView: monthView.name,
    calendars: AMENITY_CALENDARS,
    timezone: Temporal.Now.timeZoneId(),
    callbacks: {
      onRangeUpdate: (range) => {
        setVisibleRange({
          start: range.start.toPlainDate().toString(),
          end: range.end.toPlainDate().toString(),
        })
      },
    },
  })

  useEffect(() => {
    if (!calendarApp) return
    calendarApp.events.set(entries.map(mapOccupancyEntryToCalendarEvent))
  }, [calendarApp, entries])

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Calendario de ocupación'
        description='Amenidades ocupadas del condominio — vista anónima, sin datos de unidad ni residente'
      />

      <div className='overflow-hidden rounded-[16px] border border-border bg-card p-2 shadow-sm'>
        {!calendarApp ? (
          <p className='py-16 text-center text-sm text-muted-foreground'>Cargando calendario…</p>
        ) : (
          <div className='h-[70vh] min-h-[520px]'>
            <ScheduleXCalendar calendarApp={calendarApp} />
          </div>
        )}
        {calendarApp && !isLoading && entries.length === 0 && (
          <p className='py-3 text-center text-sm text-muted-foreground'>
            Sin ocupación registrada en el rango visible.
          </p>
        )}
      </div>
    </div>
  )
}
