import { ChildSummaryCards } from '@/modules/child/components/ChildSummaryCards'
import type { IAppointment } from '@/modules/appointment/schema'
import type { IFollowUp } from '@/modules/followUp/schema'
import type { IScreening } from '@/modules/screening/schema'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

const TZ = 'America/Mexico_City'

const NEXT = {
  id: 'a-1',
  starts_at: '2026-11-15T16:00:00Z',
  appointment_type: { id: 't', key: 'screening', name: 'Prueba EDI', default_duration_minutes: 30 },
  practitioner: { id: 'p', user: { name: 'Dra. Ana López' } },
} as unknown as IAppointment

const LAST = {
  id: 's-1',
  result_color: { id: 'rc', key: 'yellow', name: 'Amarillo' },
  screening_status: { id: 'st', key: 'completed', name: 'Cerrada' },
  visit: { id: 'v', visit_date: '2026-04-14' },
} as unknown as IScreening

const FOLLOW_UP = {
  id: 'f-1',
  due_date: '2026-10-30',
  follow_up_type: { id: 'ft', key: 'reassess_3_months', name: 'Revaloración a los 3 meses' },
  follow_up_status: { id: 'fs', key: 'pending', name: 'Pendiente' },
} as unknown as IFollowUp

function renderCards(props: Partial<Parameters<typeof ChildSummaryCards>[0]> = {}) {
  render(
    <MemoryRouter>
      <ChildSummaryCards
        childId='c-1'
        timeZone={TZ}
        nextAppointment={NEXT}
        lastScreening={LAST}
        openFollowUps={[FOLLOW_UP]}
        isLoading={false}
        {...props}
      />
    </MemoryRouter>
  )
}

describe('ChildSummaryCards', () => {
  it('shows the next appointment, the last EDI result and the open follow-ups', () => {
    renderCards()

    expect(screen.getByText('Próxima cita')).toBeInTheDocument()
    expect(screen.getByText(/Prueba EDI · Dra\. Ana López/)).toBeInTheDocument()
    expect(screen.getByText('Amarillo')).toBeInTheDocument()
    expect(screen.getByText('1 pendiente')).toBeInTheDocument()
    expect(screen.getByText('Reaplicación a 3 meses')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver pruebas' })).toHaveAttribute('href', '/child/c-1?tab=pruebas')
  })

  it('says what is missing instead of leaving empty cards', () => {
    renderCards({ nextAppointment: null, lastScreening: null, openFollowUps: [] })

    expect(screen.getByText('Sin citas agendadas')).toBeInTheDocument()
    expect(screen.getByText('Sin Pruebas EDI calificadas')).toBeInTheDocument()
    expect(screen.getByText('Ninguno abierto')).toBeInTheDocument()
  })
})
