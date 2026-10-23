/**
 * S4/S5 — Concentrado EDI and Reporte general pages: GET listing with the
 * mapped filters, coloured results, nutrition badges, impact flags, empty
 * state and "Exportar Excel" hitting the `/export` endpoint.
 */
import { EdiConcentratePage } from '@/modules/reports/EdiConcentratePage'
import { GeneralReportPage } from '@/modules/reports/GeneralReportPage'
import type { IEdiConcentrateRow, IImpactAxis } from '@/modules/reports/schema'
import {
  CHILD_REPORT_FILTER,
  toChildReportQuery,
} from '@/modules/reports/services/ChildReportResource'
import {
  useEdiConcentrateStore,
  useGeneralReportStore,
} from '@/modules/reports/store/childReportStores'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

/** Axes as the server sends them in `meta.impact_axes` (catalog-driven; XX proves nothing is hardcoded). */
const AXES: IImpactAxis[] = [
  { code: 'FRB', name: 'Factores de riesgo biológico' },
  { code: 'MG', name: 'Motriz gruesa' },
  { code: 'EN', name: 'Exploración Neurológica' },
  { code: 'XX', name: 'Eje de prueba' },
]

const impacts = (impacted: string[]): Record<string, boolean> =>
  Object.fromEntries(AXES.map((axis) => [axis.code, impacted.includes(axis.code)]))

const ROW: IEdiConcentrateRow = {
  id: 'child-1',
  first_name: 'Ana',
  last_name_paternal: 'Pérez',
  last_name_maternal: 'López',
  child_name: 'Ana Pérez López',
  curp: 'PELA200101MDFRPN09',
  external_id: null,
  birth_date: '2024-09-01',
  age_label: '2 año(s), 1 mes(es) y 20 día(s)',
  age_months: 25,
  health_center_name: 'Centro Norte',
  caregiver_name: 'Rosa Luna Paz',
  caregiver_phone: '5512345678',
  nutrition: {
    measured_on: '2026-10-21',
    weight_grams: 11250,
    height_mm: 845,
    weight_for_height_status: { key: 'normal_weight', name: 'Peso normal' },
    weight_for_age_status: { key: 'overweight', name: 'Sobrepeso' },
    height_for_age_status: { key: 'normal_stature', name: 'Talla normal' },
  },
  last_screening: {
    id: 's-2',
    date: '2026-08-02',
    result: { key: 'red', name: 'Rojo' },
    impacts: impacts(['EN', 'XX']),
    corrected_age_label: null,
  },
  previous_screening: {
    id: 's-1',
    date: '2026-05-14',
    result: { key: 'yellow', name: 'Amarillo' },
    impacts: impacts(['MG']),
    corrected_age_label: null,
  },
  gestational_age_weeks: 39,
  evaluator_name: 'Dra. Ruiz',
  follow_up_summary: 'Referencia a especialista (Pendiente)',
  next_evaluation: '2026-11',
}

function renderWithProviders(node: ReactNode) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>{node}</MemoryRouter>
    </QueryClientProvider>
  )
}

describe('toChildReportQuery', () => {
  it('maps list params to the GET query string of the per-child reports', () => {
    expect(
      toChildReportQuery({
        page: 2,
        limit: 20,
        search: { value: '  Ana ', case_sensitive: false },
        filters: [
          { field: CHILD_REPORT_FILTER.HEALTH_CENTER, operator: 'in', value: ['hc-1', 'hc-2'] },
          { field: CHILD_REPORT_FILTER.LAST_TEST_FROM, operator: '>=', value: '2026-01-01' },
          { field: CHILD_REPORT_FILTER.LAST_TEST_TO, operator: '<=', value: '2026-06-30' },
        ],
      })
    ).toEqual({
      page: 2,
      limit: 20,
      search: 'Ana',
      health_center_ids: ['hc-1', 'hc-2'],
      from: '2026-01-01',
      to: '2026-06-30',
    })
  })

  it('omits empty filters', () => {
    expect(toChildReportQuery({ filters: [], search: null })).toEqual({})
  })
})

describe('EdiConcentratePage', () => {
  beforeEach(() => {
    useEdiConcentrateStore.getState().removeAllEntities()
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => {
      if (url.endsWith('/export')) {
        return Promise.resolve({ data: new Blob(['xlsx-bytes']) })
      }
      return Promise.resolve({
        data: {
          data: [ROW],
          meta: {
            current_page: 1,
            per_page: 20,
            total: 1,
            last_page: 1,
            from: 1,
            to: 1,
            impact_axes: AXES,
          },
        },
      })
    })
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:mock')
    globalThis.URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders one row per child with results, impact flags and nutrition badges', async () => {
    renderWithProviders(<EdiConcentratePage />)

    expect(await screen.findByText('Ana Pérez López')).toBeInTheDocument()
    expect(apiClient.get).toHaveBeenCalledWith(
      '/reports/edi-concentrate',
      expect.objectContaining({ params: expect.any(Object) })
    )
    expect(screen.getByText('Rojo')).toBeInTheDocument()
    expect(screen.getByText('Amarillo')).toBeInTheDocument()
    expect(screen.getByLabelText('Exploración Neurológica: con impacto')).toBeInTheDocument()
    expect(screen.getByLabelText('Motriz gruesa: con impacto')).toBeInTheDocument()
    expect(screen.getByLabelText('Eje de prueba: con impacto')).toBeInTheDocument()
    expect(screen.getAllByRole('columnheader', { name: 'XX' })).toHaveLength(2)
    expect(screen.getByText('Peso normal')).toBeInTheDocument()
    expect(screen.getByText('Sobrepeso')).toBeInTheDocument()
    expect(screen.getByText('11.3 kg')).toBeInTheDocument()
    expect(screen.getByText('84.5 cm')).toBeInTheDocument()
    expect(screen.getByText('11-2026')).toBeInTheDocument()
  })

  it('exports the filtered rows through the /export endpoint', async () => {
    renderWithProviders(<EdiConcentratePage />)
    await screen.findByText('Ana Pérez López')

    fireEvent.click(screen.getByRole('button', { name: /exportar excel/i }))

    await waitFor(() => {
      const call = vi
        .mocked(apiClient.get)
        .mock.calls.find(
          ([url]) => typeof url === 'string' && url === '/reports/edi-concentrate/export'
        )
      expect(call).toBeDefined()
      expect(call?.[1]).toMatchObject({ responseType: 'blob' })
    })
  })
})

describe('GeneralReportPage', () => {
  beforeEach(() => {
    useGeneralReportStore.getState().removeAllEntities()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the empty state when no child matches', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({
      data: {
        data: [],
        meta: { current_page: 1, per_page: 20, total: 0, last_page: 1, from: null, to: null },
      },
    })

    renderWithProviders(<GeneralReportPage />)

    expect(await screen.findByText('Sin niños para mostrar')).toBeInTheDocument()
    expect(apiClient.get).toHaveBeenCalledWith('/reports/general', expect.any(Object))
  })

  it('renders the latest measurement and both Prueba EDI results', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({
      data: {
        data: [ROW],
        meta: { current_page: 1, per_page: 20, total: 1, last_page: 1, from: 1, to: 1 },
      },
    })

    renderWithProviders(<GeneralReportPage />)

    expect(await screen.findByText('Ana Pérez López')).toBeInTheDocument()
    expect(screen.getByText('Talla normal')).toBeInTheDocument()
    expect(screen.getByText('Rojo')).toBeInTheDocument()
    expect(screen.getByText('Amarillo')).toBeInTheDocument()
    expect(screen.getByText('Rosa Luna Paz')).toBeInTheDocument()
  })
})
