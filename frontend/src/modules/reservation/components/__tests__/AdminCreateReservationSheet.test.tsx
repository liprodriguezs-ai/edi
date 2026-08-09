/**
 * Tests for AdminCreateReservationSheet (admin create-reservation-on-behalf flow).
 *
 * `AmenityAvailabilityCalendar`'s own date-picking UI isn't under test here (it
 * has no test of its own yet — mocked to a single "pick a date" button).
 * `SearchSelect`'s remote `loadOptions` pickers become plain `<select>`s backed
 * by the mocked `AmenityResource.search`/`UnitResource.search`, mirroring the
 * established mocking convention from AddSurchargeDialog.test.tsx.
 */
import { RESERVATION_MODE } from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { AdminCreateReservationSheet } from '@/modules/reservation/components/AdminCreateReservationSheet'
import { RESERVATION_STATUS } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { ApiError } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/amenity/services/AmenityResource', () => ({
  AmenityResource: { search: vi.fn() },
}))

vi.mock('@/modules/unit/services/UnitResource', () => ({
  UnitResource: { search: vi.fn() },
}))

vi.mock('@/modules/reservation/services/AmenityReservationResource', () => ({
  AmenityReservationResource: { create: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/modules/amenity/components/AmenityAvailabilityCalendar', () => ({
  AmenityAvailabilityCalendar: ({ onSelectDate }: { onSelectDate: (_date: string) => void }) => (
    <button type='button' onClick={() => onSelectDate('2026-08-01')}>
      Elegir fecha
    </button>
  ),
}))

interface FakeOption {
  id: string
  name: string
}

vi.mock('@/shared/components/SearchSelect', () => ({
  SearchSelect: ({
    id,
    value,
    onChange,
    loadOptions,
  }: {
    id?: string
    value?: string | null
    onChange: (_v: string | null) => void
    loadOptions?: (_term: string) => Promise<FakeOption[]>
  }) => {
    const [options, setOptions] = useState<FakeOption[]>([])
    useEffect(() => {
      void loadOptions?.('').then(setOptions)
    }, [loadOptions])
    return (
      <select
        aria-label={id}
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value || null)}
      >
        <option value=''></option>
        {options.map((o) => (
          <option key={o.id} value={o.id}>
            {o.name}
          </option>
        ))}
      </select>
    )
  },
}))

const mockAmenitySearch = vi.mocked(AmenityResource.search)
const mockUnitSearch = vi.mocked(UnitResource.search)
const mockCreate = vi.mocked(AmenityReservationResource.create)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

const amenity = {
  id: 'amenity-1',
  name: 'Alberca',
  reservation_mode_id: RESERVATION_MODE.BY_DAY,
  rules_text: null,
  has_cost: false,
  out_of_service: false,
}

const unit = { id: 'unit-1', full_identifier: 'Casa 1', number: '1' }

async function selectAmenityAndAdvance() {
  const select = await screen.findByLabelText('admin-reserve-amenity')
  await waitFor(() => expect(select.querySelectorAll('option')).toHaveLength(2))
  fireEvent.change(select, { target: { value: 'amenity-1' } })
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
}

async function selectUnitAndAdvance() {
  const select = await screen.findByLabelText('admin-reserve-unit')
  await waitFor(() => expect(select.querySelectorAll('option')).toHaveLength(2))
  fireEvent.change(select, { target: { value: 'unit-1' } })
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
}

function selectDateAndAdvance() {
  fireEvent.click(screen.getByRole('button', { name: 'Elegir fecha' }))
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
}

describe('AdminCreateReservationSheet', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAmenitySearch.mockResolvedValue({ data: [amenity], meta: {} } as never)
    mockUnitSearch.mockResolvedValue({ data: [unit], meta: {} } as never)
  })

  it('renders the amenity picker first, then the unit picker after choosing an amenity', async () => {
    render(<AdminCreateReservationSheet isOpen onClose={vi.fn()} />, { wrapper: makeWrapper() })

    expect(await screen.findByLabelText('admin-reserve-amenity')).toBeInTheDocument()
    expect(screen.queryByLabelText('admin-reserve-unit')).not.toBeInTheDocument()

    await selectAmenityAndAdvance()

    expect(await screen.findByLabelText('admin-reserve-unit')).toBeInTheDocument()
  })

  it('walks through amenity → unit → date → review and submits with the chosen ids', async () => {
    mockCreate.mockResolvedValueOnce({
      id: 'res-1',
      amenity_id: 'amenity-1',
      unit_id: 'unit-1',
      reservation_date: '2026-08-01',
      reservation_status_id: RESERVATION_STATUS.CONFIRMED,
      amount_outstanding: 0,
    } as never)

    render(<AdminCreateReservationSheet isOpen onClose={vi.fn()} />, { wrapper: makeWrapper() })

    await selectAmenityAndAdvance()
    await selectUnitAndAdvance()
    selectDateAndAdvance()

    fireEvent.click(await screen.findByRole('button', { name: 'Crear reservación' }))

    await waitFor(() =>
      expect(mockCreate).toHaveBeenCalledWith(
        expect.objectContaining({
          amenity_id: 'amenity-1',
          unit_id: 'unit-1',
          reservation_date: '2026-08-01',
        })
      )
    )
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Reservación creada'))
  })

  it('surfaces the backend 422 via toast without swallowing it (e.g. unit out of the target)', async () => {
    const message = 'La unidad seleccionada no pertenece al alcance de esta amenidad.'
    mockCreate.mockRejectedValueOnce(new ApiError(message, 422, { message }))

    render(<AdminCreateReservationSheet isOpen onClose={vi.fn()} />, { wrapper: makeWrapper() })

    await selectAmenityAndAdvance()
    await selectUnitAndAdvance()
    selectDateAndAdvance()

    fireEvent.click(await screen.findByRole('button', { name: 'Crear reservación' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith({ title: message }))
    expect(mockCreate).toHaveBeenCalledTimes(1)
  })
})
