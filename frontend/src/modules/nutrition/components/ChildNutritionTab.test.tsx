import { ChildNutritionTab } from '@/modules/nutrition/components/ChildNutritionTab'
import { ScreeningNutritionalStatus } from '@/modules/nutrition/components/ScreeningNutritionalStatus'
import { apiClient } from '@/shared/api/apiClient'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Stable snapshot: useSyncExternalStore loops forever on a fresh object per call.
const MOCK_SESSION_STATE = { profile: { abilities: ['*'] } }

vi.mock('@/shared/auth/sessionStore', () => ({
  sessionStore: {
    getState: () => MOCK_SESSION_STATE,
    subscribe: () => () => {},
  },
}))

const ASSESSMENT = {
  id: 'a-1',
  child_id: 'child-1',
  screening_id: null,
  measured_on: '2025-01-10',
  age_months: 24,
  weight_grams: 9700,
  height_mm: 810,
  notes: null,
  weight_for_height_status: { id: 's3', key: 'mild_malnutrition', name: 'Desnutrición leve' },
  weight_for_age_status: { id: 's2', key: 'moderate_malnutrition', name: 'Desnutrición moderada' },
  height_for_age_status: { id: 's7', key: 'short_stature', name: 'Talla baja' },
}

function wrap(node: ReactNode) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}>{node}</QueryClientProvider>)
}

describe('nutrition components', () => {
  beforeEach(() => {
    vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: [ASSESSMENT], meta: null } })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('ChildNutritionTab lists the child history with the server statuses', async () => {
    wrap(<ChildNutritionTab childId="child-1" />)

    expect(await screen.findByText('Desnutrición leve')).toBeInTheDocument()
    expect(screen.getByText('Desnutrición moderada')).toBeInTheDocument()
    expect(screen.getByText('Talla baja')).toBeInTheDocument()
    expect(screen.getByText('9.70 kg')).toBeInTheDocument()
    expect(screen.getByText('81.0 cm')).toBeInTheDocument()
    expect(screen.getByText('24 meses')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Registrar medición/ })).toBeInTheDocument()

    const [url, body] = vi.mocked(apiClient.post).mock.calls[0]
    expect(url).toBe('/nutritional_assessment/search')
    expect(JSON.stringify(body)).toContain('"child_id"')
  })

  it('ScreeningNutritionalStatus shows the three badges of the screening assessment', async () => {
    wrap(<ScreeningNutritionalStatus screeningId="scr-1" />)

    expect(await screen.findByText('Desnutrición leve')).toBeInTheDocument()
    expect(screen.getByText('Talla baja')).toBeInTheDocument()
    await waitFor(() => {
      const [, body] = vi.mocked(apiClient.post).mock.calls[0]
      expect(JSON.stringify(body)).toContain('"screening_id"')
      expect(JSON.stringify(body)).toContain('scr-1')
    })
  })

  it('ScreeningNutritionalStatus explains when the Prueba EDI has no somatometry', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: [], meta: null } })
    wrap(<ScreeningNutritionalStatus screeningId="scr-2" />)

    expect(await screen.findByText('Sin somatometría registrada en esta Prueba EDI.')).toBeInTheDocument()
  })
})
