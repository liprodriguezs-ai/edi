// Ver `reservationCalendarMapping.ts` — este side-effect import expone `Temporal` como
// GLOBAL en tiempo de ejecución (requerido por `@schedule-x/calendar`) y trae la
// declaración ambient de tipos que usa `useCalendarApp`/`ScheduleXCalendar`.
import 'temporal-polyfill/global'
import '@schedule-x/theme-default/dist/index.css'

import { Button } from '@/components/ui/button'
import { useAmenityFilterOptions } from '@/modules/reservation/hooks/useAmenityFilterOptions'
import {
  AMENITY_CALENDARS,
  amenityColorHex,
  mapReservationToCalendarEvent,
} from '@/modules/reservation/reservationCalendarMapping'
import type { IAmenityReservation } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { createViewDay, createViewMonthGrid, createViewWeek } from '@schedule-x/calendar'
import { ScheduleXCalendar, useCalendarApp } from '@schedule-x/react'
import { useQuery } from '@tanstack/react-query'
import { addDays, endOfMonth, format, startOfMonth } from 'date-fns'
import { useEffect, useMemo, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'

interface VisibleRange {
  start: string
  end: string
}

/**
 * Rango inicial ANTES de que schedule-x calcule su rango real de la vista por
 * defecto (mes): mes actual con 7 días de margen a cada lado — cubre de sobra
 * los días de relleno de un month-grid (5-6 semanas) sin depender de replicar
 * su algoritmo de grilla. `onRangeUpdate` corrige al rango exacto en la
 * primera navegación del usuario.
 */
function initialVisibleRange(): VisibleRange {
  const now = new Date()
  return {
    start: format(addDays(startOfMonth(now), -7), 'yyyy-MM-dd'),
    end: format(addDays(endOfMonth(now), 7), 'yyyy-MM-dd'),
  }
}

const RESERVATION_INCLUDES = [{ relation: 'amenity' }, { relation: 'unit' }]

/**
 * Calendario admin de reservaciones (S13b): todas las reservaciones del clúster,
 * coloreadas por amenidad (`amenityCalendarId`) y filtrables por amenidad. Trae
 * SOLO las reservaciones del rango visible (acotado, se re-consulta al cambiar
 * de rango o de filtro) — nunca el historial completo.
 *
 * S18 PII guard: esta vista muestra unidad + amenidad por evento (el include
 * `unit` de RESERVATION_INCLUDES), así que un viewer sin `update.amenity_reservation`
 * (residente/renter, comité de vigilancia) se redirige al calendario de
 * ocupación ANONIMIZADO (`/amenity-occupancy`) en vez de ver este — mismo
 * patrón que `AmenityReservationListPage`'s redirect a `/my-reservations`.
 */
export const AmenityReservationCalendarPage = () => {
  const navigate = useNavigate()
  const canManage = usePermissions('update.amenity_reservation')
  const [visibleRange, setVisibleRange] = useState<VisibleRange>(initialVisibleRange)
  const [amenityIds, setAmenityIds] = useState<string[]>([])
  const amenityFilterOptions = useAmenityFilterOptions()

  const filterOptions = useMemo<MultiSelectOption[]>(
    () =>
      amenityFilterOptions.options.map((option) => ({
        ...option,
        color: amenityColorHex(option.value),
      })),
    [amenityFilterOptions.options]
  )

  const { data, isLoading } = useQuery({
    queryKey: ['amenity-reservation', 'calendar', visibleRange.start, visibleRange.end, amenityIds],
    queryFn: () =>
      AmenityReservationResource.search({
        filters: [
          { field: 'reservation_date', operator: '>=', value: visibleRange.start },
          { field: 'reservation_date', operator: '<=', value: visibleRange.end },
          ...(amenityIds.length > 0
            ? [{ field: 'amenity_id', operator: 'in' as const, value: amenityIds }]
            : []),
        ],
        includes: RESERVATION_INCLUDES,
        sort: [{ field: 'reservation_date', direction: 'asc' as const }],
        limit: 300,
      }),
    staleTime: 30 * 1000,
  })

  const reservations = useMemo<IAmenityReservation[]>(() => data?.data ?? [], [data])

  const monthView = useMemo(() => createViewMonthGrid(), [])

  // El config queda CONGELADO al primer render: `useCalendarApp` solo llama
  // `createCalendar()` una vez (efecto con deps []). Por eso `callbacks` solo
  // puede referenciar funciones ESTABLES entre renders (`setVisibleRange` del
  // propio `useState`, `navigate` de react-router) — nunca `visibleRange`/
  // `amenityIds` directamente. Los datos (eventos) se empujan después, de
  // forma imperativa, vía `calendarApp.events.set(...)` en el efecto de abajo.
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
      onEventClick: (event) => {
        navigate(`/amenity-reservation/${event.id}`)
      },
    },
  })

  useEffect(() => {
    if (!calendarApp) return
    calendarApp.events.set(reservations.map(mapReservationToCalendarEvent))
  }, [calendarApp, reservations])

  if (!canManage) {
    return <Navigate to='/amenity-occupancy' replace />
  }

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Calendario de reservaciones'
        description='Reservaciones de amenidades del condominio, coloreadas por amenidad'
        actions={
          <Button
            variant='outline'
            className='rounded-full'
            onClick={() => navigate('/amenity-reservation')}
          >
            Ver lista
          </Button>
        }
      />

      <MultiSelectFilter
        label='Amenidad'
        options={filterOptions}
        value={amenityIds}
        onChange={setAmenityIds}
        placeholder='Todas las amenidades'
        onFirstOpen={amenityFilterOptions.enable}
        isLoading={amenityFilterOptions.isLoading}
      />

      <div className='overflow-hidden rounded-[16px] border border-border bg-card p-2 shadow-sm'>
        {!calendarApp ? (
          <p className='py-16 text-center text-sm text-muted-foreground'>Cargando calendario…</p>
        ) : (
          <div className='h-[70vh] min-h-[520px]'>
            <ScheduleXCalendar calendarApp={calendarApp} />
          </div>
        )}
        {calendarApp && !isLoading && reservations.length === 0 && (
          <p className='py-3 text-center text-sm text-muted-foreground'>
            Sin reservaciones en el rango visible.
          </p>
        )}
      </div>
    </div>
  )
}
