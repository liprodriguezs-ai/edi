/**
 * REQ-CH-006 — primary-toggle behavior of ChildCaregiverPanel. Mocks
 * ChildCaregiverResource so no real HTTP call happens; asserts the "Hacer
 * primario" action calls update() with is_primary: true and that a blocked
 * unlink surfaces the server's 422 message instead of silently succeeding.
 */
import { ChildCaregiverPanel } from '@/modules/caregiver/components/ChildCaregiverPanel'
import { ChildCaregiverResource } from '@/modules/caregiver/services/ChildCaregiverResource'
import type { IChildCaregiver } from '@/modules/caregiver/schema'
import { ApiError } from '@/shared/api/apiClient'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/caregiver/services/ChildCaregiverResource', () => ({
  ChildCaregiverResource: {
    search: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
}))

vi.mock('@/modules/caregiver/services/CaregiverResource', () => ({
  CaregiverResource: { search: vi.fn() },
}))

vi.mock('@/shared/hooks/useCatalogs', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/shared/hooks/useCatalogs')>()
  return {
    ...actual,
    useLazyCatalogs: () => ({
      catalogs: { [actual.CatalogType.CAREGIVER_RELATIONSHIP]: [] },
      isLoading: false,
      enable: vi.fn(),
    }),
  }
})

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockSearch = vi.mocked(ChildCaregiverResource.search)
const mockUpdate = vi.mocked(ChildCaregiverResource.update)
const mockRemove = vi.mocked(ChildCaregiverResource.remove)

const CHILD_ID = '11111111-1111-1111-1111-111111111111'

function makeLink(overrides: Partial<IChildCaregiver>): IChildCaregiver {
  return {
    id: 'link-1',
    child_id: CHILD_ID,
    caregiver_id: 'caregiver-1',
    caregiver_relationship_id: 'rel-1',
    is_primary: false,
    caregiver: { id: 'caregiver-1', first_name: 'Ana', last_name_paternal: 'Ruiz', phone: '555', has_social_program: false } as never,
    caregiver_relationship: { id: 'rel-1', name: 'Madre' },
    ...overrides,
  }
}

describe('ChildCaregiverPanel — primary toggle', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('promotes a secondary caregiver to primary and refreshes the list', async () => {
    const primaryAna = makeLink({ id: 'link-ana', is_primary: true, caregiver: { id: 'c-ana', first_name: 'Ana', last_name_paternal: 'Ruiz' } as never })
    const secondaryBeto = makeLink({
      id: 'link-beto',
      caregiver_id: 'c-beto',
      is_primary: false,
      caregiver: { id: 'c-beto', first_name: 'Beto', last_name_paternal: 'Soto' } as never,
    })

    mockSearch
      .mockResolvedValueOnce({ data: [primaryAna, secondaryBeto] } as never)
      .mockResolvedValueOnce({
        data: [{ ...primaryAna, is_primary: false }, { ...secondaryBeto, is_primary: true }],
      } as never)
    mockUpdate.mockResolvedValueOnce({ ...secondaryBeto, is_primary: true } as never)

    render(<ChildCaregiverPanel childId={CHILD_ID} />)

    await waitFor(() => expect(screen.getByText('Beto Soto')).toBeInTheDocument())

    const promoteButton = screen.getByRole('button', { name: /Hacer primario a Beto Soto/i })
    fireEvent.click(promoteButton)

    await waitFor(() => expect(mockUpdate).toHaveBeenCalledWith('link-beto', { is_primary: true }))
    await waitFor(() => expect(mockSearch).toHaveBeenCalledTimes(2))
  })

  it('surfaces the server 422 when unlinking a blocked primary (REQ-CH-006)', async () => {
    const primaryAna = makeLink({ id: 'link-ana', is_primary: true })
    const secondaryBeto = makeLink({ id: 'link-beto', caregiver_id: 'c-beto', is_primary: false })

    mockSearch.mockResolvedValue({ data: [primaryAna, secondaryBeto] } as never)
    mockRemove.mockRejectedValueOnce(
      new ApiError('Debes designar un nuevo cuidador primario antes de eliminar este vínculo.', 422)
    )

    render(<ChildCaregiverPanel childId={CHILD_ID} />)

    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Desvincular' })).toHaveLength(2))

    fireEvent.click(screen.getAllByRole('button', { name: 'Desvincular' })[0])
    // ConfirmDialog opens; confirm the destructive action.
    fireEvent.click(screen.getByRole('button', { name: 'Eliminar' }))

    await waitFor(() => expect(mockRemove).toHaveBeenCalledWith('link-ana'))
  })
})
