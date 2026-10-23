import { ChildAppointmentsPanel } from '@/modules/appointment/components/ChildAppointmentsPanel'
import type { IAppointment } from '@/modules/appointment/schema'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'], roles: [], tenant: { timezone: 'UTC' } } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

vi.mock('@/modules/appointment/services/AppointmentResource', () => ({
  AppointmentResource: {
    search: vi.fn(),
    suggestion: vi.fn(),
    start: vi.fn(),
    arrive: vi.fn(),
  },
}))

import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { addCivilDays, tenantToday } from '@/shared/utils/tenantTime'

const CHILD = { id: 'c-1', first_name: 'Ana', last_name_paternal: 'Cruz', last_name_maternal: null }
const TODAY = tenantToday('UTC')

function appointment(id: string, startsAt: string, status: string): IAppointment {
  return {
    id,
    child_id: 'c-1',
    starts_at: startsAt,
    ends_at: startsAt,
    child: CHILD,
    practitioner: { id: 'p-1', user: { name: 'Dra. Ana López' } },
    appointment_status: { id: `st-${status}`, key: status, name: status === 'arrived' ? 'En espera' : 'Agendada' },
    appointment_type: { id: 't', key: 'check_up', name: 'Control de niño sano', default_duration_minutes: 30 },
  } as unknown as IAppointment
}

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ChildAppointmentsPanel child={CHILD} />
      </MemoryRouter>
    </QueryClientProvider>
  )
}

beforeEach(() => {
  vi.mocked(AppointmentResource.search).mockResolvedValue({
    data: [
      appointment('later', `${addCivilDays(TODAY, 7)}T15:00:00Z`, 'scheduled'),
      appointment('now', `${TODAY}T00:00:00Z`, 'arrived'),
    ],
  } as never)
  vi.mocked(AppointmentResource.suggestion).mockRejectedValue(new Error('no suggestion'))
  vi.mocked(AppointmentResource.start).mockReset()
})

describe('ChildAppointmentsPanel', () => {
  it("starts care from today's appointment of the child", async () => {
    vi.mocked(AppointmentResource.start).mockResolvedValue(appointment('now', `${TODAY}T00:00:00Z`, 'fulfilled'))
    renderPanel()

    fireEvent.click(await screen.findByRole('button', { name: /^Iniciar atención/ }))

    await waitFor(() => expect(AppointmentResource.start).toHaveBeenCalledWith('now'))
  })

  it('opens an appointment to reschedule or cancel it', async () => {
    renderPanel()

    fireEvent.click(await screen.findByRole('button', { name: /^Ver cita/ }))

    expect(await screen.findByRole('button', { name: /Reprogramar/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Cancelar/ })).toBeInTheDocument()
  })
})
