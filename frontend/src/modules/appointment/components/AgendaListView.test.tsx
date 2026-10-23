import { AgendaListView } from '@/modules/appointment/components/AgendaListView'
import type { IAppointment } from '@/modules/appointment/schema'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

const TZ = 'America/Mexico_City'

function appointment(id: string, startsAt: string, status: string, name: string): IAppointment {
  return {
    id,
    child_id: `c-${id}`,
    starts_at: startsAt,
    ends_at: startsAt,
    child: { id: `c-${id}`, first_name: name, last_name_paternal: 'Cruz', last_name_maternal: null },
    practitioner: { id: 'p-1', user: { name: 'Dra. Ana López' } },
    appointment_status: { id: `st-${status}`, key: status, name: status === 'arrived' ? 'En espera' : 'Agendada' },
    appointment_type: { id: 'ty-1', key: 'screening', name: 'Prueba EDI', default_duration_minutes: 30 },
  } as unknown as IAppointment
}

function renderList(overrides: Partial<Parameters<typeof AgendaListView>[0]> = {}) {
  const props = {
    appointments: [
      appointment('a', '2026-10-21T15:00:00Z', 'arrived', 'Valentina'),
      appointment('b', '2026-10-22T16:30:00Z', 'scheduled', 'Diego'),
    ],
    timeZone: TZ,
    today: '2026-10-21',
    canStart: true,
    startingId: null,
    onStart: vi.fn(),
    onSelect: vi.fn(),
    ...overrides,
  }
  render(
    <MemoryRouter>
      <AgendaListView {...props} />
    </MemoryRouter>
  )
  return props
}

describe('AgendaListView', () => {
  it('groups appointments by day with their time, child, type and status', () => {
    renderList()

    expect(screen.getByText('Hoy · miércoles 21 de octubre')).toBeInTheDocument()
    expect(screen.getByText('Mañana · jueves 22 de octubre')).toBeInTheDocument()
    expect(screen.getByText('09:00')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Valentina Cruz' })).toHaveAttribute('href', '/child/c-a')
    expect(screen.getAllByText('Prueba EDI · Dra. Ana López')).toHaveLength(2)
    expect(screen.getByText('En espera')).toBeInTheDocument()
  })

  it('offers "Iniciar atención" for today and opens the detail otherwise', () => {
    const props = renderList()

    fireEvent.click(screen.getByRole('button', { name: 'Iniciar atención de Valentina Cruz' }))
    expect(props.onStart).toHaveBeenCalledWith(expect.objectContaining({ id: 'a' }))

    fireEvent.click(screen.getByRole('button', { name: 'Ver cita de Diego Cruz' }))
    expect(props.onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 'b' }))
  })

  it('without permission to start care, today also opens the detail', () => {
    renderList({ canStart: false })

    expect(screen.queryByRole('button', { name: /Iniciar atención/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ver cita de Valentina Cruz' })).toBeInTheDocument()
  })

  it('says so when the range has no appointments', () => {
    renderList({ appointments: [] })

    expect(screen.getByText('No hay citas en estos días.')).toBeInTheDocument()
  })
})
