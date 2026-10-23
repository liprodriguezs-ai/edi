/**
 * REQ-SUI-006 scenario — colors render EXCLUSIVELY from the server payload's
 * `result_color.key`; fixtures double as living documentation of the
 * consumed shapes (design D15).
 */
import { GlobalResultCard } from '@/modules/screening/components/GlobalResultCard'
import { ScreeningTimeline } from '@/modules/screening/components/ScreeningTimeline'
import { SemaphoreTable } from '@/modules/screening/components/SemaphoreTable'
import type { IScreening, IScreeningAreaResult, IScreeningScoreRun } from '@/modules/screening/schema'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

const AREA_RESULTS: IScreeningAreaResult[] = [
  {
    id: 'ar-1',
    screening_id: 's-1',
    development_area_id: 'da-mg',
    result_color_id: 'c-green',
    red_count: 0,
    yellow_count: 0,
    green_count: 3,
    answered_own_count: 3,
    answered_alternate_count: 0,
    red_threshold: 2,
    green_threshold: 1,
    allows_yellow: true,
    development_area: { id: 'da-mg', code: 'MG', name: 'Motor grueso' },
    result_color: { id: 'c-green', key: 'green' },
  },
  {
    id: 'ar-2',
    screening_id: 's-1',
    development_area_id: 'da-mf',
    result_color_id: 'c-red',
    red_count: 2,
    yellow_count: 1,
    green_count: 0,
    answered_own_count: 3,
    answered_alternate_count: 0,
    red_threshold: 2,
    green_threshold: 1,
    allows_yellow: true,
    development_area: { id: 'da-mf', code: 'MF', name: 'Motor fino' },
    result_color: { id: 'c-red', key: 'red' },
  },
]

describe('SemaphoreTable', () => {
  it('shows exactly the colors present in the area_results payload', () => {
    render(<SemaphoreTable areaResults={AREA_RESULTS} />)

    expect(screen.getByText('Motor grueso')).toBeInTheDocument()
    expect(screen.getByText('Motor fino')).toBeInTheDocument()

    // Column headers also say "Rojo"/"Amarillo"/"Verde" — scope to the Color
    // column cells only (the fixture-rendered semaphore) to avoid a false pass.
    const badgeTexts = screen
      .getAllByRole('row')
      .slice(1)
      .map((row) => row.querySelectorAll('td')[1]?.textContent)
    expect(badgeTexts).toEqual(['Verde', 'Rojo'])
  })

  it('lists the areas in the same official order as the capture screen', () => {
    render(<SemaphoreTable areaResults={[AREA_RESULTS[1], AREA_RESULTS[0]]} />)

    const names = screen.getAllByRole('row').slice(1).map((row) => row.querySelector('td')?.textContent)
    expect(names).toEqual(['Motor grueso', 'Motor fino'])
  })

  it('shows an empty state when the screening is not yet scored', () => {
    render(<SemaphoreTable areaResults={[]} />)
    expect(screen.getByText('Aún sin calificar.')).toBeInTheDocument()
  })
})

function screeningFixture(overrides: Partial<IScreening> = {}): IScreening {
  return {
    id: 's-1',
    visit_id: 'v-1',
    child_id: 'c-1',
    instrument_version_id: 'iv-1',
    age_group_id: 'g-1',
    applied_age_group_id: 'g-1',
    chronological_age_days: 300,
    corrected_age_days: null,
    weight_grams: null,
    height_mm: null,
    head_circumference_mm: null,
    screening_status_id: 'st-scored',
    result_color_id: 'c-red',
    global_result_message_id: 'msg-1',
    situation_id: 'sit-1',
    alert_sign_count: 2,
    risk_factor_count: 1,
    engine_version: '1.0.0',
    rules_fingerprint: 'abc',
    scored_at: '2026-01-01T00:00:00.000Z',
    completed_at: null,
    previous_screening_id: null,
    previous_result_color_id: null,
    notes: null,
    result_color: { id: 'c-red', key: 'red' },
    global_result_message: {
      id: 'msg-1',
      key: 'red',
      headline: 'Se detectó riesgo de retraso.',
      referral_items: ['Referir a evaluación', 'Notificar a la familia'],
    },
    ...overrides,
  }
}

describe('GlobalResultCard', () => {
  it('renders the headline, referral bullets and counters exclusively from the payload', () => {
    render(<GlobalResultCard screening={screeningFixture()} />)

    expect(screen.getByText('Se detectó riesgo de retraso.')).toBeInTheDocument()
    expect(screen.getByText('Referir a evaluación')).toBeInTheDocument()
    expect(screen.getByText('Notificar a la familia')).toBeInTheDocument()
    expect(screen.getByText('Rojo')).toBeInTheDocument()
  })

  it('shows an empty state when there is no result color', () => {
    render(<GlobalResultCard screening={screeningFixture({ result_color_id: null, result_color: null })} />)
    expect(screen.getByText('Aún sin calificar.')).toBeInTheDocument()
  })
})

describe('ScreeningTimeline', () => {
  it('renders trigger, authoritative flag, version, engine and color from score_runs', () => {
    const runs: IScreeningScoreRun[] = [
      {
        id: 'run-1',
        screening_id: 's-1',
        instrument_version_id: 'iv-1',
        is_authoritative: true,
        trigger: 'initial',
        reason: null,
        engine_version: '1.0.0',
        rules_fingerprint: 'abc',
        result_color_id: 'c-red',
        instrument_version: { id: 'iv-1', key: 'edi-2022-2ed' },
        result_color: { id: 'c-red', key: 'red' },
        created_at: '2026-01-01T00:00:00.000Z',
      },
    ]

    render(<ScreeningTimeline scoreRuns={runs} />)

    expect(screen.getByText('Calificación inicial')).toBeInTheDocument()
    expect(screen.getByText('Autoritativo')).toBeInTheDocument()
    expect(screen.getByText(/edi-2022-2ed/)).toBeInTheDocument()
    expect(screen.getByText('Rojo')).toBeInTheDocument()
  })

  it('shows an empty state with no score runs', () => {
    render(<ScreeningTimeline scoreRuns={[]} />)
    expect(screen.getByText('Sin corridas de calificación registradas.')).toBeInTheDocument()
  })
})
