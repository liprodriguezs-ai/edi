/**
 * S6 — the calculator only formats what the server computed. Pins the request
 * shape (reference date optional -> server uses the tenant's today) and the
 * Spanish rendering of the result.
 */
import { formatAgeBreakdown, notApplicableReasonCopy, readyFromCopy } from '@/modules/corrected-age/schema'
import { CorrectedAgeResource } from '@/modules/corrected-age/services/CorrectedAgeResource'
import { apiClient } from '@/shared/api/apiClient'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/api/apiClient', () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error {},
  formatApiErrorMessage: vi.fn(),
}))

describe('CorrectedAgeResource.calculate', () => {
  beforeEach(() => {
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.get).mockResolvedValue({ data: { data: { applies: true } } })
  })

  it('omits an empty reference date so the server uses the tenant today', async () => {
    await CorrectedAgeResource.calculate({ birth_date: '2025-01-01', gestational_weeks: 32, reference_date: '' })

    expect(apiClient.get).toHaveBeenCalledWith('/screening/corrected-age', {
      params: { birth_date: '2025-01-01', gestational_weeks: 32 },
    })
  })

  it('sends the reference date when given', async () => {
    await CorrectedAgeResource.calculate({
      birth_date: '2025-01-01',
      gestational_weeks: 32,
      reference_date: '2025-04-01',
    })

    expect(apiClient.get).toHaveBeenCalledWith('/screening/corrected-age', {
      params: { birth_date: '2025-01-01', gestational_weeks: 32, reference_date: '2025-04-01' },
    })
  })
})

describe('corrected-age copy', () => {
  it('renders "X meses Y días"', () => {
    expect(formatAgeBreakdown({ days: 34, months: 1, remaining_days: 4 })).toBe('1 mes 4 días')
    expect(formatAgeBreakdown({ days: 91, months: 3, remaining_days: 1 })).toBe('3 meses 1 día')
  })

  it('renders a corrected age before the due date in days only', () => {
    expect(formatAgeBreakdown({ days: -50, months: 0, remaining_days: -50 })).toBe('-50 días')
  })

  it('says from which date the Prueba EDI can be applied', () => {
    expect(readyFromCopy('2025-03-14')).toMatch(/^Aplica la Prueba EDI a partir del .+, cuando su edad corregida cumpla 1 mes\.$/)
  })

  it('explains why the correction does not apply', () => {
    expect(notApplicableReasonCopy('term_birth')).toMatch(/37 semanas/)
    expect(notApplicableReasonCopy('over_two_years')).toMatch(/2 años/)
  })
})
