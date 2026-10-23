/**
 * Wizard create-once (StrictMode) + VISIT_ALREADY_SCREENED redirect
 * (REQ-SUI-003 scenarios). Mocks the Resource layer with fixtures,
 * following the InstrumentVersionDetailPage.test.tsx approach (design D15).
 */
import { ScreeningApplyPage } from '@/modules/screening/ScreeningApplyPage'
import type { IScreening, IScreeningItemBank } from '@/modules/screening/schema'
import { useScreeningDraftStore } from '@/modules/screening/store/screeningDraftStore'
import { ApiError } from '@/shared/api/apiClient'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { StrictMode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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

const BANK: IScreeningItemBank = { applied_age_group_code: 'G3', areas: [], risk_factors: [] }

function draftScreening(overrides: Partial<IScreening> = {}): IScreening {
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

function renderApplyForVisit(visitId = 'visit-1', query = '') {
  return render(
    <StrictMode>
      <MemoryRouter initialEntries={[`/screening/apply/${visitId}${query}`]}>
        <Routes>
          <Route path="/screening/apply/:visitId" element={<ScreeningApplyPage />} />
          <Route path="/screening/:id/apply" element={<div>resume page</div>} />
          <Route path="/screening/:id" element={<div>detail page</div>} />
        </Routes>
      </MemoryRouter>
    </StrictMode>
  )
}

describe('ScreeningApplyPage — create-once + VISIT_ALREADY_SCREENED', () => {
  beforeEach(() => {
    useScreeningDraftStore.getState().reset()
    vi.mocked(ScreeningResource.createDraft).mockReset()
    vi.mocked(ScreeningResource.findByVisitId).mockReset()
  })

  it('issues exactly one create call under StrictMode double-invoke', async () => {
    vi.mocked(ScreeningResource.createDraft).mockResolvedValue({
      data: draftScreening(),
      item_bank: BANK,
    })

    renderApplyForVisit()

    await waitFor(() => {
      expect(ScreeningResource.createDraft).toHaveBeenCalledTimes(1)
    })
  })

  it('resolves VISIT_ALREADY_SCREENED by redirecting to the existing screening', async () => {
    vi.mocked(ScreeningResource.createDraft).mockRejectedValue(
      new ApiError('Esta visita ya tiene un tamizaje registrado.', 422, {
        message: 'Esta visita ya tiene un tamizaje registrado.',
        code: 'VISIT_ALREADY_SCREENED',
      })
    )
    vi.mocked(ScreeningResource.findByVisitId).mockResolvedValue(
      draftScreening({ id: 's-existing', screening_status: { id: 'st-draft', key: 'draft', name: 'Borrador' } })
    )

    renderApplyForVisit()

    await waitFor(() => {
      expect(screen.getByText('resume page')).toBeInTheDocument()
    })
  })

  /**
   * The lookup that recovers the existing screening runs INSIDE the catch block,
   * so when it threw, the exception escaped every handler and the page rendered
   * nothing at all: no message, no link to the screening that does exist. The
   * route it calls was returning 405 in the real API, which is exactly how it was
   * found -- but any failure (network, permission) reproduces it.
   */
  it('shows a message instead of a blank page when the recovery lookup fails', async () => {
    vi.mocked(ScreeningResource.createDraft).mockRejectedValue(
      new ApiError('Esta visita ya tiene un tamizaje registrado.', 422, {
        message: 'Esta visita ya tiene un tamizaje registrado.',
        code: 'VISIT_ALREADY_SCREENED',
      })
    )
    vi.mocked(ScreeningResource.findByVisitId).mockRejectedValue(new Error('boom'))

    renderApplyForVisit()

    await waitFor(() => {
      expect(screen.getByText('Esta visita ya tiene un tamizaje registrado.')).toBeInTheDocument()
    })
  })
})

/**
 * S2/S3/S7 (odd/tasks/edi-legacy-parity.md) — the chosen group travels from the
 * picker (`?grupo=`) to the create call; the header shows "Grupo N", its range,
 * the "A elección" badge, the group's material and the láminas/manual links.
 */
describe('ScreeningApplyPage — grupo, a elección y láminas', () => {
  const G10 = {
    id: 'g-10',
    code: 'G10',
    name: 'GRUPO 10',
    ordinal: 10,
    min_age_months: 19,
    max_age_months: 24,
    materials_note: 'Frasco con tapa rosca y pelota antiponchable.',
  }

  function renderResume() {
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
    vi.mocked(ScreeningResource.createDraft).mockReset()
    vi.mocked(ScreeningResource.show).mockReset()
    vi.mocked(ScreeningResource.items).mockReset()
  })

  it('passes the ?grupo= group to the create call', async () => {
    vi.mocked(ScreeningResource.createDraft).mockResolvedValue({ data: draftScreening(), item_bank: BANK })

    renderApplyForVisit('visit-1', '?grupo=g-10')

    await waitFor(() => {
      expect(ScreeningResource.createDraft).toHaveBeenCalledWith({ visit_id: 'visit-1', age_group_id: 'g-10' })
    })
  })

  it('shows the chosen group prominently with its badge, range and material', async () => {
    vi.mocked(ScreeningResource.show).mockResolvedValue(
      draftScreening({
        age_group_selection_mode: 'chosen',
        age_group_id: 'g-8',
        applied_age_group_id: 'g-10',
        age_group: { id: 'g-8', code: 'G8', name: 'GRUPO 8', ordinal: 8 },
        applied_age_group: G10,
      })
    )
    vi.mocked(ScreeningResource.items).mockResolvedValue({ item_bank: { ...BANK, applied_age_group_code: 'G10' } })

    renderResume()

    expect(await screen.findByTestId('applied-group')).toHaveTextContent('Grupo 10')
    expect(screen.getByText('19 a 24 meses')).toBeInTheDocument()
    expect(screen.getByText('A elección')).toBeInTheDocument()
    expect(screen.getByText(/Frasco con tapa rosca/)).toBeInTheDocument()
    // A chosen group is not a corrected-age substitution.
    expect(screen.queryByText(/sustituido por edad corregida/)).not.toBeInTheDocument()
  })

  it('does not flag an age-derived group as chosen', async () => {
    vi.mocked(ScreeningResource.show).mockResolvedValue(
      draftScreening({ age_group_selection_mode: 'age', applied_age_group: G10, age_group: G10 })
    )
    vi.mocked(ScreeningResource.items).mockResolvedValue({ item_bank: BANK })

    renderResume()

    expect(await screen.findByTestId('applied-group')).toHaveTextContent('Grupo 10')
    expect(screen.queryByText('A elección')).not.toBeInTheDocument()
  })

  it('links the four láminas and the manual in a new tab', async () => {
    vi.mocked(ScreeningResource.show).mockResolvedValue(draftScreening({ applied_age_group: G10 }))
    vi.mocked(ScreeningResource.items).mockResolvedValue({ item_bank: BANK })

    renderResume()

    const expected: Array<[string, string]> = [
      ['Lámina A', '/edi/lamina-a.jpeg'],
      ['Lámina B', '/edi/lamina-b.jpeg'],
      ['Lámina C', '/edi/lamina-c.jpeg'],
      ['Lámina D', '/edi/lamina-d.jpeg'],
      ['Manual', '/edi/manual-edi-2a-edicion.pdf'],
    ]
    for (const [label, href] of expected) {
      const link = await screen.findByRole('link', { name: label })
      expect(link).toHaveAttribute('href', href)
      expect(link).toHaveAttribute('target', '_blank')
      expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    }
  })
})
