/**
 * Characterization tests for CatalogChecklist.
 *
 * Asserts:
 * - Empty catalog shows amber warning badge + deep-link
 * - Non-empty catalog shows count badge + deep-link
 */
import { CatalogChecklist } from '@/modules/import/components/CatalogChecklist'
import { CatalogType } from '@/shared/hooks/useCatalogs'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ── Mock apiClient used by useCatalogs ────────────────────────────────────────
vi.mock('@/shared/api/apiClient', () => ({
  apiClient: {
    post: vi.fn(),
  },
}))

import { apiClient } from '@/shared/api/apiClient'

const mockPost = vi.mocked(apiClient.post)

function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    )
  }
}

describe('CatalogChecklist', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows amber warning badge when unit_type catalog is empty', async () => {
    mockPost.mockResolvedValue({
      data: {
        data: {
          [CatalogType.UNIT_TYPE]: [],
          [CatalogType.UNIT_STATUS]: [],
          [CatalogType.BANK_ACCOUNT]: [],
        },
      },
    })

    render(<CatalogChecklist />, { wrapper: makeWrapper() })

    await waitFor(() => {
      const emptyBadge = screen.getByTestId(`catalog-badge-empty-${CatalogType.UNIT_TYPE}`)
      expect(emptyBadge).toBeInTheDocument()
      expect(emptyBadge).toHaveTextContent(/vacío/i)
    })

    // Deep-link must be present and point to /unit-type
    const link = screen.getByTestId(`catalog-link-${CatalogType.UNIT_TYPE}`)
    expect(link).toBeInTheDocument()
    expect(link).toHaveAttribute('href', '/unit-type')
  })

  it('shows count badge when unit_type catalog has entries', async () => {
    mockPost.mockResolvedValue({
      data: {
        data: {
          [CatalogType.UNIT_TYPE]: [
            { id: 'ut-1', name: 'Casa' },
            { id: 'ut-2', name: 'Departamento' },
          ],
          [CatalogType.UNIT_STATUS]: [],
          [CatalogType.BANK_ACCOUNT]: [],
        },
      },
    })

    render(<CatalogChecklist />, { wrapper: makeWrapper() })

    await waitFor(() => {
      const countBadge = screen.getByTestId(`catalog-badge-count-${CatalogType.UNIT_TYPE}`)
      expect(countBadge).toBeInTheDocument()
      expect(countBadge).toHaveTextContent('2')
    })

    const link = screen.getByTestId(`catalog-link-${CatalogType.UNIT_TYPE}`)
    expect(link).toHaveAttribute('href', '/unit-type')
  })

  it('renders deep-links for all three catalog items', async () => {
    mockPost.mockResolvedValue({
      data: {
        data: {
          [CatalogType.UNIT_TYPE]: [],
          [CatalogType.UNIT_STATUS]: [],
          [CatalogType.BANK_ACCOUNT]: [],
        },
      },
    })

    render(<CatalogChecklist />, { wrapper: makeWrapper() })

    await waitFor(() => {
      expect(screen.getByTestId(`catalog-link-${CatalogType.UNIT_TYPE}`)).toHaveAttribute('href', '/unit-type')
      expect(screen.getByTestId(`catalog-link-${CatalogType.UNIT_STATUS}`)).toHaveAttribute('href', '/unit-status')
      expect(screen.getByTestId(`catalog-link-${CatalogType.BANK_ACCOUNT}`)).toHaveAttribute('href', '/bank-account')
    })
  })
})
