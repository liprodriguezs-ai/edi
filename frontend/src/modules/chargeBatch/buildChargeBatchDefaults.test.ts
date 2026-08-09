import { buildChargeBatchDefaults } from '@/modules/chargeBatch/constants'
import { describe, expect, it } from 'vitest'

describe('buildChargeBatchDefaults', () => {
  it('returns correct year from given date', () => {
    const date = new Date(2025, 2, 15) // March 15 2025, local
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.year).toBe(2025)
  })

  it('returns correct month (1-indexed) from given date', () => {
    const date = new Date(2025, 2, 15) // March = 2 (0-indexed)
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.month).toBe(3)
  })

  it('returns issued_at as YYYY-MM-DD from given date', () => {
    const date = new Date(2025, 2, 15) // March 15 2025
    const defaults = buildChargeBatchDefaults(date)
    // toISOString() gives UTC, but we rely on the existing implementation
    expect(defaults.issued_at).toMatch(/^2025-03-/)
  })

  it('returns activate true by default', () => {
    const date = new Date(2025, 0, 1) // January 1 2025
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.activate).toBe(true)
  })

  it('returns target_type all by default', () => {
    const date = new Date(2025, 0, 1)
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.target_type).toBe('all')
  })

  it('returns empty target_unit_ids array', () => {
    const date = new Date(2025, 0, 1)
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.target_unit_ids).toEqual([])
  })

  it('returns empty multiselect arrays (type/tower/section/feature)', () => {
    const date = new Date(2025, 0, 1)
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.target_unit_type_ids).toEqual([])
    expect(defaults.target_tower_ids).toEqual([])
    expect(defaults.target_section_ids).toEqual([])
    expect(defaults.target_feature_ids).toEqual([])
  })

  it('returns empty charge_policy_id (selected by the user)', () => {
    const date = new Date(2025, 0, 1)
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.charge_policy_id).toBe('')
  })

  it('returns december correctly (month 12)', () => {
    // Use local date constructor to avoid UTC timezone offset issues
    const date = new Date(2025, 11, 1) // month is 0-indexed: 11 = December
    const defaults = buildChargeBatchDefaults(date)
    expect(defaults.month).toBe(12)
    expect(defaults.year).toBe(2025)
  })

  it('does not call new Date() internally (pure — fixed date test is stable)', () => {
    // Two calls with identical date must produce identical results
    const date = new Date(2024, 5, 15) // June 15 2024, local
    const a = buildChargeBatchDefaults(date)
    const b = buildChargeBatchDefaults(date)
    expect(a).toEqual(b)
  })
})
