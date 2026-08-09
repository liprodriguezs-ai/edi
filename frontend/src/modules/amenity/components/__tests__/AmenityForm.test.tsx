/**
 * Smoke test para el bloque "Alcance" del `AmenityForm` (amenity-visibility-target,
 * Slice C): el `FieldSet` "Alcance" se renderiza y arranca con `target_type='all'`
 * preseleccionado en modo alta — mismo comportamiento que `buildAmenityFormDefaults`
 * (ver schema.test.ts) expuesto a través del form real.
 */
import { AmenityForm } from '@/modules/amenity/components/AmenityForm'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { CrudMode } from '@/shared/enums/CrudMode'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/amenity/services/AmenityResource', () => ({
  AmenityResource: { create: vi.fn(), update: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/shared/hooks/useCatalogs', async () => {
  const actual = await vi.importActual<typeof import('@/shared/hooks/useCatalogs')>(
    '@/shared/hooks/useCatalogs'
  )
  return {
    ...actual,
    useCatalogs: () => ({
      catalogs: {
        [actual.CatalogType.RESERVATION_MODE]: [],
        [actual.CatalogType.BANK_ACCOUNT]: [],
        [actual.CatalogType.UNIT_TYPE]: [],
        [actual.CatalogType.TOWER]: [],
        [actual.CatalogType.SECTION]: [],
        [actual.CatalogType.UNIT_FEATURE]: [],
      },
      isLoading: false,
    }),
  }
})

void AmenityResource // referenced to keep the import used

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('AmenityForm — Alcance', () => {
  it('renders the "Alcance" fieldset with target_type=all preselected on create mode', () => {
    render(
      <AmenityForm mode={CrudMode.CREATE} amenity={null} onSaved={vi.fn()} onCancel={vi.fn()} />,
      { wrapper: makeWrapper() }
    )

    expect(screen.getByText('Alcance')).toBeInTheDocument()

    const allOption = screen.getByRole('radio', { name: /Todo el condominio/ })
    expect(allOption).toHaveAttribute('aria-checked', 'true')
  })
})
