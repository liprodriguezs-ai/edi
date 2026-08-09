/**
 * Smoke test de AmenityReservationCalendarPage.
 *
 * Mockea `@schedule-x/react`/`@schedule-x/calendar` por completo (la librería en sí
 * queda fuera de la superficie de aserción — ver instrucción de la tarea): solo se
 * verifica que la página monta y que los eventos mapeados se empujan al
 * `calendarApp` simulado vía `events.set(...)`.
 *
 * S18 PII guard: este calendario (con nombre de unidad) solo se renderiza para
 * un viewer con `update.amenity_reservation` (board/admin); sin ese permiso se
 * redirige al calendario de ocupación anonimizado (`/amenity-occupancy`) — mismo
 * patrón de mock que `AmenityReservationListPage.test.tsx`.
 */
import { AmenityReservationCalendarPage } from '@/modules/reservation/AmenityReservationCalendarPage'
import { RESERVATION_STATUS, type IAmenityReservation } from '@/modules/reservation/schema'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/hooks/usePermissions', () => ({
  usePermissions: vi.fn(() => true),
}))

const eventsSetMock = vi.fn()
const useCalendarAppMock = vi.fn((_config: unknown) => ({ events: { set: eventsSetMock } }))

vi.mock('@schedule-x/react', () => ({
  useCalendarApp: (config: unknown) => useCalendarAppMock(config),
  ScheduleXCalendar: () => <div data-testid='schedule-x-calendar-stub' />,
}))

vi.mock('@schedule-x/calendar', () => ({
  createViewMonthGrid: () => ({ name: 'month-grid' }),
  createViewWeek: () => ({ name: 'week' }),
  createViewDay: () => ({ name: 'day' }),
}))

let mockReservations: IAmenityReservation[] = []

vi.mock('@/modules/reservation/services/AmenityReservationResource', () => ({
  AmenityReservationResource: {
    search: vi.fn(() => Promise.resolve({ data: mockReservations, meta: {} })),
  },
}))

vi.mock('@/modules/reservation/hooks/useAmenityFilterOptions', () => ({
  useAmenityFilterOptions: () => ({ options: [], isLoading: false, enable: vi.fn() }),
}))

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

const mockPermissions = vi.mocked(usePermissions)

describe('AmenityReservationCalendarPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockReservations = []
    // Board/admin viewer by default (canManage=true unblocks the S18 redirect
    // guard); the redirect test below overrides this to false.
    mockPermissions.mockReturnValue(true)
  })

  it('monta la página con el encabezado y el calendario simulado', async () => {
    render(<AmenityReservationCalendarPage />, { wrapper: makeWrapper() })

    expect(screen.getByText('Calendario de reservaciones')).toBeInTheDocument()
    expect(await screen.findByTestId('schedule-x-calendar-stub')).toBeInTheDocument()
  })

  it('empuja los eventos mapeados al calendarApp cuando llegan las reservaciones', async () => {
    mockReservations = [
      {
        id: 'reservation-1',
        amenity_id: 'amenity-alberca',
        unit_id: 'unit-1',
        reservation_date: '2026-08-01',
        reservation_status_id: RESERVATION_STATUS.CONFIRMED,
        amenity: { id: 'amenity-alberca', name: 'Alberca' },
        unit: { id: 'unit-1', full_identifier: 'Casa 12' },
      } as IAmenityReservation,
      {
        id: 'reservation-2',
        amenity_id: 'amenity-gimnasio',
        unit_id: 'unit-2',
        reservation_date: '2026-08-02',
        start_at: '2026-08-02T15:00:00.000000Z',
        end_at: '2026-08-02T16:00:00.000000Z',
        reservation_status_id: RESERVATION_STATUS.CONFIRMED,
        amenity: { id: 'amenity-gimnasio', name: 'Gimnasio' },
        unit: { id: 'unit-2', full_identifier: 'Casa 8' },
      } as IAmenityReservation,
    ]

    render(<AmenityReservationCalendarPage />, { wrapper: makeWrapper() })

    // La primera llamada ocurre al montar (aún sin datos: arreglo vacío); esperamos
    // a la llamada que ya trae las 2 reservaciones mapeadas tras resolver la query.
    await waitFor(() => {
      expect(eventsSetMock.mock.calls.at(-1)?.[0]).toHaveLength(2)
    })

    const lastCallEvents = eventsSetMock.mock.calls.at(-1)?.[0] as Array<{
      id: string
      title: string
    }>
    expect(lastCallEvents).toHaveLength(2)
    expect(lastCallEvents.map((e) => e.id)).toEqual(['reservation-1', 'reservation-2'])
    expect(lastCallEvents[0].title).toBe('Alberca — Casa 12')
    expect(lastCallEvents[1].title).toBe('Gimnasio — Casa 8')
  })

  it('redirige a /amenity-occupancy cuando el viewer no tiene update.amenity_reservation', () => {
    mockPermissions.mockReturnValue(false)

    render(<AmenityReservationCalendarPage />, { wrapper: makeWrapper() })

    expect(screen.queryByText('Calendario de reservaciones')).not.toBeInTheDocument()
  })
})
