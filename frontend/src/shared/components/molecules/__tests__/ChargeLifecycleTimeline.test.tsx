import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ChargeLifecycleTimeline } from '@/shared/components/molecules/ChargeLifecycleTimeline'
import type { LifecycleMilestones } from '@/shared/utils/chargeLifecycle'

const milestones: LifecycleMilestones = {
  generationDate: '2026-06-01',
  dueSoonDate: '2026-06-23',
  dueDate: '2026-06-30',
  overdueDate: '2026-07-05',
}

describe('ChargeLifecycleTimeline', () => {
  it('renderiza 4 nodos en modo paramétrico', () => {
    render(<ChargeLifecycleTimeline mode="parametric" milestones={milestones} />)
    expect(screen.getByRole('list', { name: 'Ciclo de vida del cobro' })).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(4)
    expect(screen.getByText('Nace')).toBeInTheDocument()
    expect(screen.getByText('Vence')).toBeInTheDocument()
    expect(screen.getByText('Moroso')).toBeInTheDocument()
  })

  it('marca el nodo activo con aria-current="step"', () => {
    const { container } = render(
      <ChargeLifecycleTimeline mode="concrete" milestones={milestones} activeStage="overdue" />
    )
    const current = container.querySelectorAll('[aria-current="step"]')
    expect(current).toHaveLength(1)
  })

  it('en modo concrete el nodo "Nace" muestra la fecha de generación (no "—")', () => {
    const { container } = render(
      <ChargeLifecycleTimeline mode="concrete" milestones={milestones} />
    )
    // 4 nodos con fecha real (ningún <time> en "—")
    const times = Array.from(container.querySelectorAll('time'))
    expect(times).toHaveLength(4)
    expect(times.every((t) => t.textContent !== '—')).toBe(true)
    expect(container.querySelector('time[datetime="2026-06-01"]')).toBeInTheDocument()
  })

  it('muestra el aviso informativo en modo manual', () => {
    render(<ChargeLifecycleTimeline mode="manual" />)
    expect(
      screen.getByText('Para cobros eventuales, tú defines las fechas al crear el cobro.')
    ).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Ciclo de vida del cobro' })).not.toBeInTheDocument()
  })
})
