import { ChildFollowUpsPanel } from '@/modules/followUp/components/ChildFollowUpsPanel'
import type { IFollowUp } from '@/modules/followUp/schema'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

function followUp(id: string, type: string, due: string, status: string, name: string): IFollowUp {
  return {
    id,
    due_date: due,
    follow_up_type: { id: `t-${type}`, key: type, name: type },
    follow_up_status: { id: `s-${status}`, key: status, name },
  } as unknown as IFollowUp
}

function renderPanel(followUps: IFollowUp[]) {
  render(
    <MemoryRouter>
      <ChildFollowUpsPanel followUps={followUps} isLoading={false} />
    </MemoryRouter>
  )
}

describe('ChildFollowUpsPanel', () => {
  it('lists the open follow-ups first, then the closed ones, each opening its detail', () => {
    renderPanel([
      followUp('done', 'reassess_3_months', '2026-07-14', 'completed', 'Completado'),
      followUp('late', 'specialist_referral', '2026-09-01', 'overdue', 'Vencido'),
      followUp('soon', 'confirmatory_test', '2026-11-20', 'pending', 'Pendiente'),
    ])

    const rows = screen.getAllByRole('listitem')
    expect(rows.map((row) => within(row).getByRole('link').getAttribute('href'))).toEqual([
      '/follow-up/late',
      '/follow-up/soon',
      '/follow-up/done',
    ])
    expect(within(rows[0]).getByText('Referencia a especialista')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Vencido')).toBeInTheDocument()
  })

  it('says so when the child has no follow-ups', () => {
    renderPanel([])

    expect(screen.getByText('Este niño no tiene seguimientos.')).toBeInTheDocument()
  })
})
