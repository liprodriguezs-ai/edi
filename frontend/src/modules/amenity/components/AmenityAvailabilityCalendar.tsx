import { Calendar } from '@/components/ui/calendar'
import {
  availabilityReasonLabel,
  buildAvailabilityDayMap,
  freeSlotsForDay,
} from '@/modules/amenity/amenityAvailability'
import { RESERVATION_MODE, type IAmenity } from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { cn } from '@/lib/utils'
import {
  displayFormatDate,
  formatLocalDateString,
  formatTimeHHmm,
  parseLocalDateString,
  todayLocalDateString,
} from '@/shared/utils/dateHelper'
import { useQuery } from '@tanstack/react-query'
import { endOfMonth, startOfMonth } from 'date-fns'
import { es } from 'date-fns/locale'
import { useMemo, useState } from 'react'

export interface AmenityAvailabilityCalendarProps {
  amenity: Pick<IAmenity, 'id' | 'reservation_mode_id' | 'out_of_service'>
  selectedDate: string | null
  onSelectDate: (_date: string) => void
  selectedSlotId: string | null
  onSelectSlot: (_slotId: string) => void
}

/**
 * Picker de fecha (y horario, si la amenidad opera `by_slot`) compartido por
 * la vista de exploración de disponibilidad del residente (browse) y el paso
 * "fecha/horario" del wizard de reservación (S15) — UNA sola fuente de
 * verdad para no duplicar el fetch/mapeo de `GET /amenity/{id}/availability`.
 *
 * El backend decide TODO (día cerrado/bloqueado/sin cupo); el front solo
 * pinta `disabled`/`available` tal cual llega — nunca recalcula weekday o
 * blackouts localmente.
 */
export function AmenityAvailabilityCalendar({
  amenity,
  selectedDate,
  onSelectDate,
  selectedSlotId,
  onSelectSlot,
}: AmenityAvailabilityCalendarProps) {
  // BUG FIX (E2E QA round 2): initialize on the MONTH of `selectedDate` when
  // one is already provided, instead of always today's month. The wizard
  // (re)mounts this component fresh with `initialDate`/`initialSlotId`
  // preloaded from the exploration view (S14→S15), and it also remounts on
  // every return to the "datetime" step (conditionally rendered per step) —
  // both are fresh mounts, so this lazy initializer alone keeps the fetched
  // availability month aligned with the pre-selected date. Without it, the
  // calendar always mounted on the CURRENT month, fetched availability for
  // THAT month, and `dayMap` never had the already-selected (future-month)
  // date — the summary panel showed "No disponible" for a date that actually
  // has free slots.
  const [visibleMonth, setVisibleMonth] = useState<Date>(() =>
    selectedDate ? parseLocalDateString(selectedDate) : new Date()
  )
  const today = todayLocalDateString()
  const isBySlot = amenity.reservation_mode_id === RESERVATION_MODE.BY_SLOT

  // El backend rechaza fechas pasadas (422) — si el mes visible es el actual,
  // arrancamos el rango en HOY en vez del día 1. `startMonth` más abajo ya
  // evita que el residente navegue a un mes anterior al actual.
  const rangeStart = useMemo(() => {
    const monthStart = formatLocalDateString(startOfMonth(visibleMonth))
    return monthStart < today ? today : monthStart
  }, [visibleMonth, today])
  const rangeEnd = useMemo(() => formatLocalDateString(endOfMonth(visibleMonth)), [visibleMonth])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['amenity-availability', amenity.id, rangeStart, rangeEnd],
    queryFn: () => AmenityResource.availability(amenity.id, rangeStart, rangeEnd),
    enabled: Boolean(amenity.id) && !amenity.out_of_service && rangeStart <= rangeEnd,
  })

  const dayMap = useMemo(() => buildAvailabilityDayMap(data), [data])
  const selectedDay = selectedDate ? dayMap.get(selectedDate) : undefined
  const slots = isBySlot ? freeSlotsForDay(selectedDay) : []
  const selectedDateObj = selectedDate ? parseLocalDateString(selectedDate) : undefined

  const isDayDisabled = (date: Date): boolean => {
    const key = formatLocalDateString(date)
    if (key < today) return true
    const day = dayMap.get(key)
    // No cargado todavía (fuera del mes visible) o cerrado/sin cupo.
    return !day?.available
  }

  if (amenity.out_of_service) {
    return (
      <div className='rounded-[14px] border border-[#F0D6CE] bg-[#FBEEE9] px-4 py-3 text-[13px] text-[#C0533C]'>
        Esta amenidad está fuera de servicio por el momento — no se pueden hacer reservaciones.
      </div>
    )
  }

  return (
    <div className='space-y-4'>
      <div className='flex justify-center rounded-[14px] border border-border bg-card p-3 shadow-sm'>
        {isLoading && !data ? (
          <p className='px-3 py-8 text-center text-[13px] text-[var(--ink-3)]'>
            Cargando disponibilidad…
          </p>
        ) : isError ? (
          <p className='px-3 py-8 text-center text-[13px] text-[var(--ink-3)]'>
            No se pudo cargar la disponibilidad. Intenta de nuevo.
          </p>
        ) : (
          <Calendar
            mode='single'
            locale={es}
            month={visibleMonth}
            onMonthChange={setVisibleMonth}
            startMonth={new Date()}
            selected={selectedDateObj}
            onSelect={(date) => {
              if (date) onSelectDate(formatLocalDateString(date))
            }}
            disabled={isDayDisabled}
          />
        )}
      </div>

      {/* Leyenda: los días del calendario SON seleccionables (elegir fecha para
          reservar); los deshabilitados (cerrados/bloqueados/sin cupo) llegan
          atenuados desde el backend. La leyenda explica esa distinción visual. */}
      <div className='flex items-center gap-4 px-1 text-[11.5px] text-[var(--ink-3)]'>
        <span className='inline-flex items-center gap-1.5'>
          <span
            className='size-2.5 rounded-full border border-[var(--green)] bg-[var(--green-bg)]'
            aria-hidden
          />
          Disponible
        </span>
        <span className='inline-flex items-center gap-1.5'>
          <span
            className='size-2.5 rounded-full border border-border bg-[var(--surface-2)] opacity-60'
            aria-hidden
          />
          No disponible
        </span>
      </div>

      {selectedDate && (
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            {displayFormatDate(selectedDate, 'mediumDate')}
          </div>
          {!isBySlot ? (
            <p className='mt-1.5 text-[13.5px] text-foreground'>
              {availabilityReasonLabel(selectedDay)}
            </p>
          ) : slots.length === 0 ? (
            <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
              {availabilityReasonLabel(selectedDay)}
            </p>
          ) : (
            <div className='mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3'>
              {slots.map((slot) => {
                const isFull = slot.free <= 0
                const isSelected = selectedSlotId === slot.slot_id
                return (
                  <button
                    key={slot.slot_id}
                    type='button'
                    disabled={isFull}
                    onClick={() => onSelectSlot(slot.slot_id)}
                    className={cn(
                      'rounded-[10px] border px-3 py-2 text-left text-[12.5px] transition-colors',
                      isFull && 'cursor-not-allowed opacity-40',
                      isSelected
                        ? 'border-[var(--green)] bg-[var(--green-bg)]'
                        : 'border-border hover:bg-[var(--surface-2)]'
                    )}
                  >
                    <div className='font-medium'>
                      {formatTimeHHmm(slot.start_time)} – {formatTimeHHmm(slot.end_time)}
                    </div>
                    <div className='text-[11px] text-[var(--ink-3)]'>
                      {isFull ? 'Sin cupo' : `${slot.free} de ${slot.capacity} libres`}
                    </div>
                  </button>
                )
              })}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
