/**
 * Capture flow UX: official section order, readable ages, and the review step
 * that replaces "Calificar de todos modos".
 */
import { ScreeningApplyPage } from '@/modules/screening/ScreeningApplyPage'
import type { IBankItem, IScreening, IScreeningItemBank } from '@/modules/screening/schema'
import { useScreeningDraftStore } from '@/modules/screening/store/screeningDraftStore'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['update.screening', 'create.appointment'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

vi.mock('@/modules/screening/services/ScreeningResource', () => ({
  ScreeningResource: {
    createDraft: vi.fn(),
    findByVisitId: vi.fn(),
    show: vi.fn(),
    items: vi.fn(),
    update: vi.fn(),
    answer: vi.fn(),
    score: vi.fn(),
  },
}))

import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'

function item(id: string): IBankItem {
  return {
    screening_item_id: id,
    code: id,
    question: `Pregunta ${id}`,
    is_alternate: false,
    locked: false,
    locked_reason: null,
    options: [
      { screening_item_option_id: `${id}-si`, label: 'SI' },
      { screening_item_option_id: `${id}-no`, label: 'NO' },
    ],
  }
}

const BANK: IScreeningItemBank = {
  applied_age_group_code: 'G3',
  areas: [
    { development_area_id: 'a-mg', code: 'MG', name: 'Motriz gruesa', items: [item('mg1')] },
    { development_area_id: 'a-en', code: 'EN', name: 'Exploración neurológica', items: [item('en1')] },
    { development_area_id: 'a-ale', code: 'ALE', name: 'Señales de alerta', items: [item('ale1')] },
  ],
  risk_factors: [
    { risk_factor_id: 'rf1', code: 'FRB1', question: '¿Hospitalización?', locked: false, locked_reason: null },
  ],
}

function screening(overrides: Partial<IScreening> = {}): IScreening {
  return {
    id: 's-1',
    visit_id: 'v-1',
    child_id: 'c-1',
    instrument_version_id: 'iv-1',
    age_group_id: 'g-3',
    applied_age_group_id: 'g-3',
    chronological_age_days: 102,
    corrected_age_days: null,
    weight_grams: null,
    height_mm: null,
    head_circumference_mm: null,
    screening_status_id: 'st-draft',
    result_color_id: null,
    global_result_message_id: null,
    situation_id: null,
    alert_sign_count: null,
    risk_factor_count: null,
    engine_version: null,
    rules_fingerprint: null,
    scored_at: null,
    completed_at: null,
    previous_screening_id: null,
    previous_result_color_id: null,
    notes: null,
    screening_status: { id: 'st-draft', key: 'draft', name: 'Borrador' },
    child: { id: 'c-1', first_name: 'Ana', last_name_paternal: 'Pérez' },
    ...overrides,
  }
}

function answered(ids: string[]): Partial<IScreening> {
  return {
    answers: ids.map((id) => ({
      id: `ans-${id}`,
      screening_id: 's-1',
      screening_item_id: id,
      screening_item_option_id: `${id}-si`,
      result_color_id: 'color-green',
      is_auto_derived: false,
    })),
  }
}

function renderResume(overrides: Partial<IScreening> = {}) {
  vi.mocked(ScreeningResource.show).mockResolvedValue(screening(overrides))
  vi.mocked(ScreeningResource.items).mockResolvedValue({ item_bank: BANK })
  return render(
    <MemoryRouter initialEntries={['/screening/s-1/apply']}>
      <Routes>
        <Route path="/screening/:id/apply" element={<ScreeningApplyPage />} />
      </Routes>
    </MemoryRouter>
  )
}

beforeEach(() => {
  useScreeningDraftStore.getState().reset()
  vi.mocked(ScreeningResource.show).mockReset()
  vi.mocked(ScreeningResource.items).mockReset()
  vi.mocked(ScreeningResource.score).mockReset()
})

describe('ScreeningApplyPage — capture flow', () => {
  it('follows the official form order: risk factors and alert signs, axes, neurological exam', async () => {
    renderResume()

    await screen.findByText('Motriz gruesa', { selector: 'h2' })
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)
    const order = ['Factores de riesgo', 'Señales de alerta', 'Motriz gruesa', 'Exploración neurológica'].map((name) =>
      headings.indexOf(name)
    )

    expect(order.every((index) => index >= 0)).toBe(true)
    expect([...order].sort((a, b) => a - b)).toEqual(order)
  })

  it('shows the age in months and days', async () => {
    renderResume({ chronological_age_days: 102 })

    expect(await screen.findByText('3 meses 12 días')).toBeInTheDocument()
  })

  it('reviews before scoring and blocks scoring while items are missing', async () => {
    renderResume(answered(['mg1']))

    fireEvent.click(await screen.findByRole('button', { name: 'Revisar y calificar' }))

    expect(screen.getByRole('heading', { name: 'Revisión antes de calificar' })).toBeInTheDocument()
    expect(screen.getByText('Faltan 2 reactivos para poder calificar.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Calificar' })).toBeDisabled()
    expect(screen.queryByText('Calificar de todos modos')).not.toBeInTheDocument()
  })

  it('goes back to the missing area from the review', async () => {
    renderResume(answered(['mg1']))
    fireEvent.click(await screen.findByRole('button', { name: 'Revisar y calificar' }))

    fireEvent.click(screen.getByRole('button', { name: 'Contestar Señales de alerta' }))

    expect(screen.queryByRole('heading', { name: 'Revisión antes de calificar' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Revisar y calificar' })).toBeInTheDocument()
  })

  it('scores from the review once everything is answered', async () => {
    vi.mocked(ScreeningResource.score).mockResolvedValue({ data: screening() })
    renderResume(answered(['mg1', 'en1', 'ale1']))
    fireEvent.click(await screen.findByRole('button', { name: 'Revisar y calificar' }))

    const scoreButton = screen.getByRole('button', { name: 'Calificar' })
    expect(scoreButton).toBeEnabled()
    fireEvent.click(scoreButton)

    await waitFor(() => expect(vi.mocked(ScreeningResource.score)).toHaveBeenCalledWith('s-1'))
  })

  it('shows where the evaluator is: capturing, then reviewing', async () => {
    renderResume()

    const current = await screen.findByText('Capturar respuestas')
    expect(current.closest('li')).toHaveAttribute('aria-current', 'step')

    fireEvent.click(screen.getByRole('button', { name: 'Revisar y calificar' }))
    expect(screen.getByText('Revisar y calificar', { selector: 'li span' }).closest('li')).toHaveAttribute(
      'aria-current',
      'step'
    )
  })

  it('after scoring, the next step is closing the test and scheduling the appointment', async () => {
    renderResume({
      result_color_id: 'color-green',
      screening_status: { id: 'st-scored', key: 'scored', name: 'Calificado' },
    })

    expect(await screen.findByRole('button', { name: 'Cerrar prueba y agendar cita' })).toBeInTheDocument()
    expect(screen.getByText('Cerrar y agendar cita').closest('li')).toHaveAttribute('aria-current', 'step')
  })

  it('correcting a scored test warns that it must be scored again', async () => {
    renderResume({
      result_color_id: 'color-green',
      screening_status: { id: 'st-scored', key: 'scored', name: 'Calificado' },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Corregir respuestas' }))

    expect(screen.getByText(/tendrás que calificarla de nuevo/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Revisar y calificar' })).toBeInTheDocument()
  })

  it('goes back to the tests of the child record', async () => {
    renderResume()

    expect(await screen.findByRole('link', { name: /Volver al expediente/ })).toHaveAttribute(
      'href',
      '/child/c-1?tab=pruebas'
    )
  })
})
