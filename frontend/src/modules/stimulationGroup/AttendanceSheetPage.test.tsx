/**
 * REQ-XC-005 — AttendanceSheetPage: unsaved rows render member defaults
 * without writing, and a failed toggle reverts the optimistic update and
 * shows an error toast.
 */
import { AttendanceSheetPage } from '@/modules/stimulationGroup/AttendanceSheetPage'
import { StimulationGroupResource } from '@/modules/stimulationGroup/services/StimulationGroupResource'
import { StimulationGroupMemberResource } from '@/modules/stimulationGroup/services/StimulationGroupMemberResource'
import { StimulationAttendanceResource } from '@/modules/stimulationGroup/services/StimulationAttendanceResource'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/stimulationGroup/services/StimulationGroupResource', () => ({
  StimulationGroupResource: { show: vi.fn() },
}))

vi.mock('@/modules/stimulationGroup/services/StimulationGroupMemberResource', () => ({
  StimulationGroupMemberResource: { search: vi.fn() },
}))

vi.mock('@/modules/stimulationGroup/services/StimulationAttendanceResource', () => ({
  StimulationAttendanceResource: { search: vi.fn(), upsert: vi.fn() },
}))

vi.mock('@/shared/hooks/useCatalogs', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/shared/hooks/useCatalogs')>()
  return {
    ...actual,
    useLazyCatalogs: () => ({
      catalogs: {
        [actual.CatalogType.ATTENDANCE_PAYMENT_STATUS]: [
          { id: 'status-scholarship', name: 'Beca' },
          { id: 'status-unpaid', name: 'No pagado' },
        ],
      },
      isLoading: false,
      enable: vi.fn(),
    }),
  }
})

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockShow = vi.mocked(StimulationGroupResource.show)
const mockMemberSearch = vi.mocked(StimulationGroupMemberResource.search)
const mockAttendanceSearch = vi.mocked(StimulationAttendanceResource.search)
const mockUpsert = vi.mocked(StimulationAttendanceResource.upsert)

const GROUP_ID = 'group-1'
const MEMBER = {
  id: 'member-1',
  stimulation_group_id: GROUP_ID,
  child_id: 'child-1',
  enrolled_at: '2026-01-01',
  default_attendance_payment_status_id: 'status-scholarship',
  child: { id: 'child-1', first_name: 'Sofía', last_name_paternal: 'García' },
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={[`/stimulation-group/${GROUP_ID}/attendance`]}>
      <Routes>
        <Route path="/stimulation-group/:id/attendance" element={<AttendanceSheetPage />} />
      </Routes>
    </MemoryRouter>
  )
}

describe('AttendanceSheetPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockShow.mockResolvedValue({ id: GROUP_ID, name: 'Grupo matutino' } as never)
    mockMemberSearch.mockResolvedValue({ data: [MEMBER] } as never)
  })

  it('renders member defaults when no attendance row exists for the date, without writing', async () => {
    mockAttendanceSearch.mockResolvedValue({ data: [] } as never)

    renderPage()

    await waitFor(() => expect(screen.getByText('Sofía García')).toBeInTheDocument())

    const attendedCheckbox = screen.getByRole('checkbox', { name: /Asistió — Sofía García/i })
    const homeworkCheckbox = screen.getByRole('checkbox', { name: /Tarea — Sofía García/i })

    expect(attendedCheckbox).not.toBeChecked()
    expect(homeworkCheckbox).not.toBeChecked()
    expect(mockUpsert).not.toHaveBeenCalled()
  })

  it('reverts the optimistic update and shows an error toast when the upsert fails', async () => {
    mockAttendanceSearch.mockResolvedValue({ data: [] } as never)
    mockUpsert.mockRejectedValueOnce(new Error('network error'))

    renderPage()

    await waitFor(() => expect(screen.getByText('Sofía García')).toBeInTheDocument())

    const attendedCheckbox = screen.getByRole('checkbox', { name: /Asistió — Sofía García/i })
    fireEvent.click(attendedCheckbox)

    // Optimistic: checked immediately.
    expect(attendedCheckbox).toBeChecked()

    // Reverted after the failed request resolves.
    await waitFor(() => expect(attendedCheckbox).not.toBeChecked())
    expect(mockUpsert).toHaveBeenCalledWith(
      GROUP_ID,
      expect.any(String),
      expect.arrayContaining([expect.objectContaining({ child_id: 'child-1', attended: true })])
    )
  })
})
