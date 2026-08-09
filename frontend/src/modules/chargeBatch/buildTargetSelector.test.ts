/**
 * Characterization tests for buildTargetSelector (pure exported function in schema.ts).
 *
 * Verifies that each target_type produces the correct selector shape
 * expected by the backend TargetResolver.
 */
import { describe, expect, it } from 'vitest'
import type { ChargeBatchFormValues } from './schema'
import { buildTargetSelector } from './schema'

/** Minimal valid base values (fields not relevant to the specific target_type are omitted). */
const base: ChargeBatchFormValues = {
  charge_policy_id: 'policy-1',
  title: 'Test batch',
  year: 2026,
  month: 1,
  target_type: 'all',
  issued_at: '2026-01-01',
  due_date: '2026-02-01',
  activate: false,
}

describe('buildTargetSelector', () => {
  it('returns null for target_type "all"', () => {
    expect(buildTargetSelector({ ...base, target_type: 'all' })).toBeNull()
  })

  it('returns null for target_type "by_share"', () => {
    // by_share has no selector fields — falls through to the default: null
    expect(buildTargetSelector({ ...base, target_type: 'by_share' })).toBeNull()
  })

  it('returns null for target_type "with_vehicles" (no selector, like "all")', () => {
    expect(buildTargetSelector({ ...base, target_type: 'with_vehicles' })).toBeNull()
  })

  it('returns { unit_type_ids } for target_type "by_type" (multiselect)', () => {
    const result = buildTargetSelector({
      ...base,
      target_type: 'by_type',
      target_unit_type_ids: ['type-uuid-abc', 'type-uuid-def'],
    })
    expect(result).toEqual({ unit_type_ids: ['type-uuid-abc', 'type-uuid-def'] })
  })

  it('returns { tower_ids } for target_type "by_tower" (multiselect)', () => {
    const result = buildTargetSelector({
      ...base,
      target_type: 'by_tower',
      target_tower_ids: ['tower-uuid-xyz', 'tower-uuid-2'],
    })
    expect(result).toEqual({ tower_ids: ['tower-uuid-xyz', 'tower-uuid-2'] })
  })

  it('returns { section_ids } for target_type "by_section" (multiselect)', () => {
    const result = buildTargetSelector({
      ...base,
      target_type: 'by_section',
      target_section_ids: ['section-uuid-1', 'section-uuid-2'],
    })
    expect(result).toEqual({ section_ids: ['section-uuid-1', 'section-uuid-2'] })
  })

  it('returns { floors: [floor] } for target_type "by_floor"', () => {
    const result = buildTargetSelector({
      ...base,
      target_type: 'by_floor',
      target_floor: '3',
    })
    expect(result).toEqual({ floors: ['3'] })
  })

  it('returns { unit_ids } for target_type "manual"', () => {
    const ids = ['unit-1', 'unit-2', 'unit-3']
    const result = buildTargetSelector({
      ...base,
      target_type: 'manual',
      target_unit_ids: ids,
    })
    expect(result).toEqual({ unit_ids: ids })
  })

  it('returns { unit_feature_ids } for target_type "by_feature" (multiselect, OR)', () => {
    // by_feature carries unit_feature catalog IDs (not names) — TargetResolver matches a
    // unit if it has ANY of these features (OR semantics).
    const result = buildTargetSelector({
      ...base,
      target_type: 'by_feature',
      target_feature_ids: ['feature-uuid-pool', 'feature-uuid-gym'],
    })
    expect(result).toEqual({ unit_feature_ids: ['feature-uuid-pool', 'feature-uuid-gym'] })
  })

  it('returns { unit_id } for legacy target_type "single_unit"', () => {
    // single_unit is no longer offered in the UI, but the builder still maps legacy data.
    const result = buildTargetSelector({
      ...base,
      target_type: 'single_unit',
      target_unit_id: 'unit-uuid-007',
    })
    expect(result).toEqual({ unit_id: 'unit-uuid-007' })
  })
})
