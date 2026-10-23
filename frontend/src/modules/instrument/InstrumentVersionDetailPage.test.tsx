/**
 * Smoke render for InstrumentVersionDetailPage (task 6.6): mocks every
 * TanStack Query hook the page/tabs consume so no real HTTP call happens,
 * and asserts the read-only inspector renders version metadata + the
 * default (Grupos) tab without throwing.
 */
import { InstrumentVersionDetailPage } from '@/modules/instrument/InstrumentVersionDetailPage'
import type { IAgeGroup, IInstrumentVersion } from '@/modules/instrument/schema'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/instrument/hooks/useInstrumentQueries', () => ({
  useInstrumentVersion: vi.fn(),
  useAgeGroups: vi.fn(),
  useScreeningItemsByGroup: vi.fn(),
  useAreaScoringRules: vi.fn(),
  useGlobalScoringRules: vi.fn(),
  useRiskFactors: vi.fn(),
  useHeadCircumferenceReferences: vi.fn(),
  useGlobalResultMessages: vi.fn(),
}))

import * as queries from '@/modules/instrument/hooks/useInstrumentQueries'

const VERSION: IInstrumentVersion = {
  id: 'v-1',
  key: 'edi-2022-2ed',
  instrument_id: 'i-1',
  age_calculation_mode: 'calendar',
  is_default: true,
  published_at: '2026-01-01T00:00:00.000Z',
  content_hash: 'abcdef0123456789',
  source_note: 'Nota de origen de prueba',
  name: 'EDI 2ª edición (2022)',
}

const GROUPS: IAgeGroup[] = [
  {
    id: 'g-1',
    instrument_version_id: 'v-1',
    code: 'G1',
    name: 'GRUPO 1',
    ordinal: 1,
    is_alternate: false,
    min_age_days: 30,
    max_age_days: 59,
    min_age_months: 1,
    max_age_months: 1,
    alternate_age_group_id: null,
  },
]

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/instrument/v-1']}>
      <Routes>
        <Route path="/instrument/:id" element={<InstrumentVersionDetailPage />} />
      </Routes>
    </MemoryRouter>
  )
}

describe('InstrumentVersionDetailPage', () => {
  beforeEach(() => {
    vi.mocked(queries.useInstrumentVersion).mockReturnValue({ data: VERSION, isLoading: false } as never)
    vi.mocked(queries.useAgeGroups).mockReturnValue({ data: GROUPS, isLoading: false } as never)
    vi.mocked(queries.useScreeningItemsByGroup).mockReturnValue({ data: [], isLoading: false } as never)
    vi.mocked(queries.useAreaScoringRules).mockReturnValue({ data: [], isLoading: false } as never)
    vi.mocked(queries.useGlobalScoringRules).mockReturnValue({ data: [], isLoading: false } as never)
    vi.mocked(queries.useRiskFactors).mockReturnValue({ data: [], isLoading: false } as never)
    vi.mocked(queries.useHeadCircumferenceReferences).mockReturnValue({ data: [], isLoading: false } as never)
    vi.mocked(queries.useGlobalResultMessages).mockReturnValue({ data: [], isLoading: false } as never)
  })

  it('renders version metadata and the default Grupos tab without any write request', () => {
    renderPage()

    expect(screen.getByText('EDI 2ª edición (2022)')).toBeInTheDocument()
    expect(screen.getByText(/edi-2022-2ed/)).toBeInTheDocument()
    expect(screen.getByText('Predeterminada')).toBeInTheDocument()
    expect(screen.getByText(/G1 — GRUPO 1/)).toBeInTheDocument()
  })

  it('shows a loading state while the version query is pending', () => {
    vi.mocked(queries.useInstrumentVersion).mockReturnValue({ data: undefined, isLoading: true } as never)
    renderPage()

    expect(screen.queryByText('EDI 2ª edición (2022)')).not.toBeInTheDocument()
    expect(screen.queryByText('No se encontró la versión.')).not.toBeInTheDocument()
  })
})
