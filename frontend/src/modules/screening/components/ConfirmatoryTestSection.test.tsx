/**
 * REQ-SUI-010 visibility matrix — visible ONLY when completed AND red.
 */
import { ConfirmatoryTestSection } from '@/modules/screening/components/ConfirmatoryTestSection'
import type { IScreening } from '@/modules/screening/schema'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

// Stable reference — a mock getState() returning a fresh object each call
// would trigger useSyncExternalStore's "getSnapshot should be cached" loop.
const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

function screening(overrides: Partial<IScreening> = {}): IScreening {
  return {
    id: 's-1',
    visit_id: 'v-1',
    child_id: 'c-1',
    instrument_version_id: 'iv-1',
    age_group_id: 'g-1',
    applied_age_group_id: 'g-1',
    chronological_age_days: 100,
    corrected_age_days: null,
    weight_grams: null,
    height_mm: null,
    head_circumference_mm: null,
    screening_status_id: 'st-1',
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
    ...overrides,
  }
}

describe('ConfirmatoryTestSection visibility matrix', () => {
  it('hidden when completed but GREEN', () => {
    const { container } = render(
      <ConfirmatoryTestSection
        screening={screening({
          screening_status: { id: 'x', key: 'completed', name: 'Completado' },
          result_color: { id: 'c', key: 'green' },
        })}
        onSaved={vi.fn()}
      />
    )
    expect(container).toBeEmptyDOMElement()
  })

  it('hidden when RED but only scored (not completed)', () => {
    const { container } = render(
      <ConfirmatoryTestSection
        screening={screening({
          screening_status: { id: 'x', key: 'scored', name: 'Calificado' },
          result_color: { id: 'c', key: 'red' },
        })}
        onSaved={vi.fn()}
      />
    )
    expect(container).toBeEmptyDOMElement()
  })

  it('visible when completed AND red', () => {
    render(
      <ConfirmatoryTestSection
        screening={screening({
          screening_status: { id: 'x', key: 'completed', name: 'Completado' },
          result_color: { id: 'c', key: 'red' },
        })}
        onSaved={vi.fn()}
      />
    )
    expect(screen.getByText('Prueba confirmatoria')).toBeInTheDocument()
  })
})
