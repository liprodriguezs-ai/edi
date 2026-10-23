import { AgendaCenterSelect, ALL_CENTERS } from '@/modules/appointment/components/AgendaCenterSelect'
import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const useCatalogsMock = vi.fn()

vi.mock('@/shared/hooks/useCatalogs', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/shared/hooks/useCatalogs')>()
  return {
    ...original,
    useCatalogs: (request: unknown, options?: { enabled?: boolean }) => useCatalogsMock(request, options),
  }
})

import { CatalogType } from '@/shared/hooks/useCatalogs'

beforeEach(() => {
  useCatalogsMock.mockReset()
  useCatalogsMock.mockImplementation((_request: unknown, options?: { enabled?: boolean }) => ({
    catalogs: options?.enabled ? { [CatalogType.HEALTH_CENTER]: [{ id: 'hc-1', name: 'CS Tlalpan' }] } : {},
    isLoading: false,
  }))
})

describe('AgendaCenterSelect', () => {
  it('shows the name of the chosen center, loading the centers to know it', () => {
    render(<AgendaCenterSelect value='hc-1' onChange={vi.fn()} />)

    expect(screen.getByRole('combobox')).toHaveTextContent('CS Tlalpan')
    expect(useCatalogsMock).toHaveBeenCalledWith(expect.anything(), { enabled: true })
  })

  it('shows "Todos los centros" without asking for the centers until it opens', () => {
    render(<AgendaCenterSelect value={ALL_CENTERS} onChange={vi.fn()} />)

    expect(screen.getByRole('combobox')).toHaveTextContent('Todos los centros')
    expect(useCatalogsMock).toHaveBeenCalledWith(expect.anything(), { enabled: false })
  })
})
