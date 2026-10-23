/**
 * REQ-SUI-008 scenario — rescore defaults to simulation: `authoritative:
 * false` unless the user explicitly switches it on (D11).
 */
import { RescoreModal } from '@/modules/screening/components/RescoreModal'
import type { IScreening } from '@/modules/screening/schema'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/screening/services/ScreeningResource', () => ({
  ScreeningResource: {
    rescore: vi.fn().mockResolvedValue({ data: {}, run: {} }),
    // The version list is served by the Screening module itself: the raw
    // instrument catalog is closed to every organization role on purpose.
    rescoreVersions: vi.fn().mockResolvedValue([]),
  },
}))

import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'

function screening(): IScreening {
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
    screening_status_id: 'st-completed',
    result_color_id: 'c-green',
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
  }
}

describe('RescoreModal', () => {
  it('submits with authoritative: false by default plus the required reason', async () => {
    render(<RescoreModal screening={screening()} onClose={vi.fn()} onDone={vi.fn()} />)

    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'Verificación de rutina' } })
    fireEvent.click(screen.getByRole('button', { name: 'Verificar' }))

    await waitFor(() => {
      expect(ScreeningResource.rescore).toHaveBeenCalledWith(
        's-1',
        expect.objectContaining({ authoritative: false, reason: 'Verificación de rutina' })
      )
    })
  })
})
