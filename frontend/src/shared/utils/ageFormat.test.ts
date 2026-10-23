import { describe, expect, it } from 'vitest'
import { formatAgeDays } from './ageFormat'

describe('formatAgeDays', () => {
  it('uses 30-day months, the convention of the EDI tables', () => {
    expect(formatAgeDays(102)).toBe('3 meses 12 días')
    expect(formatAgeDays(31)).toBe('1 mes 1 día')
    expect(formatAgeDays(20)).toBe('20 días')
    expect(formatAgeDays(60)).toBe('2 meses')
  })

  it('shows an age before the due date in days only', () => {
    expect(formatAgeDays(-50)).toBe('-50 días')
  })
})
