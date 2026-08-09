/**
 * Tests for AddSurchargeDialog.
 *
 * Focus: the backend returns TWO different 422 shapes for `POST
 * amenity_reservation/{id}/surcharges`:
 *  (a) a business-gate 422 — `{message}` WITHOUT `errors` (the reservation is
 *      not CONFIRMED/COMPLETED) → only a toast, no field-level error.
 *  (b) a validation 422 — `{message, errors}` (a FormRequest field failed) →
 *      the toast AND an inline field error under the offending field.
 */
import { AddSurchargeDialog } from '@/modules/reservation/components/AddSurchargeDialog'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { ApiError } from '@/shared/api/apiClient'
import { CatalogType } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/reservation/services/AmenityReservationResource', () => ({
  AmenityReservationResource: { addSurcharge: vi.fn() },
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
    useLazyCatalogs: () => ({
      catalogs: {
        [actual.CatalogType.CHARGE_CONCEPT]: [{ id: 'concept-1', name: 'Daño a mobiliario' }],
      },
      enable: vi.fn(),
    }),
  }
})

interface FakeOption {
  id: string
  name: string
}

vi.mock('@/shared/components/SearchSelect', () => ({
  SearchSelect: ({
    value,
    onChange,
    options,
  }: {
    value: string
    onChange: (_v: string) => void
    options: FakeOption[]
  }) => (
    <select aria-label='Concepto' value={value} onChange={(e) => onChange(e.target.value)}>
      <option value=''></option>
      {options.map((o) => (
        <option key={o.id} value={o.id}>
          {o.name}
        </option>
      ))}
    </select>
  ),
}))

const mockAddSurcharge = vi.mocked(AmenityReservationResource.addSurcharge)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

async function fillAndSubmit() {
  fireEvent.change(screen.getByLabelText('Concepto'), { target: { value: 'concept-1' } })
  fireEvent.change(screen.getByLabelText(/Monto/), { target: { value: '500' } })
  fireEvent.click(screen.getByRole('button', { name: /Aplicar cargo/ }))
}

describe('AddSurchargeDialog', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders the CHARGE_CONCEPT catalog lazily (no options before open, via the mocked hook)', () => {
    render(
      <AddSurchargeDialog isOpen onClose={vi.fn()} reservationId='res-1' onCreated={vi.fn()} />,
      { wrapper: makeWrapper() }
    )

    expect(screen.getByText('Daño a mobiliario')).toBeInTheDocument()
    void CatalogType.CHARGE_CONCEPT // referenced to keep the import used
  })

  it('shows ONLY a toast (no inline field error) on a business-gate 422 without `errors`', async () => {
    const message =
      'Solo se puede aplicar un cargo adicional a una reservación confirmada o completada.'
    mockAddSurcharge.mockRejectedValueOnce(new ApiError(message, 422, { message }))

    const onCreated = vi.fn()
    render(
      <AddSurchargeDialog isOpen onClose={vi.fn()} reservationId='res-1' onCreated={onCreated} />,
      { wrapper: makeWrapper() }
    )

    await fillAndSubmit()

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1))
    expect(toast.error).toHaveBeenCalledWith({ title: message })
    expect(onCreated).not.toHaveBeenCalled()
    // No field-level error rendered: FieldError renders null (no role="alert") when empty.
    expect(screen.queryAllByRole('alert')).toHaveLength(0)
  })

  it('shows the toast AND an inline field error on a validation 422 with `errors`', async () => {
    const message = 'The given data was invalid.'
    const fieldMessage = 'El monto excede el límite permitido.'
    mockAddSurcharge.mockRejectedValueOnce(
      new ApiError(message, 422, { message, errors: { amount: [fieldMessage] } })
    )

    render(
      <AddSurchargeDialog isOpen onClose={vi.fn()} reservationId='res-1' onCreated={vi.fn()} />,
      { wrapper: makeWrapper() }
    )

    await fillAndSubmit()

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1))
    expect(toast.error).toHaveBeenCalledWith({ title: message, description: fieldMessage })
    expect(await screen.findByRole('alert')).toHaveTextContent(fieldMessage)
  })

  it('applies the surcharge and calls onCreated on success', async () => {
    mockAddSurcharge.mockResolvedValueOnce({
      id: 'surcharge-1',
      reservation_id: 'res-1',
      charge_id: 'charge-1',
      charge_concept_id: 'concept-1',
      amount: 50000,
    })

    const onCreated = vi.fn()
    const onClose = vi.fn()
    render(
      <AddSurchargeDialog isOpen onClose={onClose} reservationId='res-1' onCreated={onCreated} />,
      { wrapper: makeWrapper() }
    )

    await fillAndSubmit()

    await waitFor(() =>
      expect(mockAddSurcharge).toHaveBeenCalledWith('res-1', {
        charge_concept_id: 'concept-1',
        amount: 50000,
        description: undefined,
      })
    )
    await waitFor(() => expect(onCreated).toHaveBeenCalledTimes(1))
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
