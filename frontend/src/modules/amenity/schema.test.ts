import {
  RESERVATION_MODE,
  amenityFormSchema,
  buildAmenityFormDefaults,
  type AmenityFormValues,
  type IAmenity,
} from '@/modules/amenity/schema'
import { describe, expect, it } from 'vitest'

const baseValues = (overrides: Partial<AmenityFormValues> = {}): AmenityFormValues => ({
  name: 'Salón de eventos',
  description: '',
  restrictions: '',
  capacity: 40,
  available_weekdays: [5, 6],
  rules_text: '',
  out_of_service: false,
  out_of_service_reason: '',
  has_cost: false,
  cost_amount: null,
  requires_deposit: false,
  deposit_amount: null,
  deposit_due_days: null,
  bank_account_id: '',
  requires_approval: false,
  reservation_mode_id: RESERVATION_MODE.BY_DAY,
  target_type: 'all',
  target_unit_type_ids: [],
  target_tower_ids: [],
  target_section_ids: [],
  target_floors: [],
  target_unit_feature_ids: [],
  target_unit_id: '',
  target_unit_ids: [],
  ...overrides,
})

describe('amenityFormSchema', () => {
  it('accepts a valid amenity without cost/deposit', () => {
    expect(amenityFormSchema.safeParse(baseValues()).success).toBe(true)
  })

  it('requires at least one available weekday', () => {
    const result = amenityFormSchema.safeParse(baseValues({ available_weekdays: [] }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('available_weekdays'))).toBe(true)
    }
  })

  it('requires cost_amount and bank_account_id when has_cost is true', () => {
    const result = amenityFormSchema.safeParse(
      baseValues({ has_cost: true, cost_amount: null, bank_account_id: '' })
    )
    expect(result.success).toBe(false)
    if (!result.success) {
      const paths = result.error.issues.map((i) => i.path.join('.'))
      expect(paths).toContain('cost_amount')
      expect(paths).toContain('bank_account_id')
    }
  })

  it('accepts has_cost with a valid amount and destination account', () => {
    const result = amenityFormSchema.safeParse(
      baseValues({ has_cost: true, cost_amount: 500, bank_account_id: 'bank-1' })
    )
    expect(result.success).toBe(true)
  })

  it('requires deposit_amount and deposit_due_days when requires_deposit is true', () => {
    const result = amenityFormSchema.safeParse(
      baseValues({ requires_deposit: true, deposit_amount: null, deposit_due_days: null })
    )
    expect(result.success).toBe(false)
    if (!result.success) {
      const paths = result.error.issues.map((i) => i.path.join('.'))
      expect(paths).toContain('deposit_amount')
      expect(paths).toContain('deposit_due_days')
    }
  })

  it('accepts requires_deposit with a valid amount and due days', () => {
    const result = amenityFormSchema.safeParse(
      baseValues({ requires_deposit: true, deposit_amount: 200, deposit_due_days: 3 })
    )
    expect(result.success).toBe(true)
  })

  it('requires reservation_mode_id regardless of has_cost', () => {
    const result = amenityFormSchema.safeParse(baseValues({ reservation_mode_id: '' }))
    expect(result.success).toBe(false)
    if (!result.success) {
      const paths = result.error.issues.map((i) => i.path.join('.'))
      expect(paths).toContain('reservation_mode_id')
    }
  })

  it('rejects capacity below 1', () => {
    expect(amenityFormSchema.safeParse(baseValues({ capacity: 0 })).success).toBe(false)
  })

  it('requires target_unit_feature_ids when target_type is by_feature', () => {
    const result = amenityFormSchema.safeParse(
      baseValues({ target_type: 'by_feature', target_unit_feature_ids: [] })
    )
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('target_unit_feature_ids'))).toBe(true)
    }
  })

  it('accepts target_type=all without any selector fields', () => {
    expect(amenityFormSchema.safeParse(baseValues({ target_type: 'all' })).success).toBe(true)
  })
})

describe('buildAmenityFormDefaults', () => {
  it('defaults a new amenity to target_type=all with empty selector fields', () => {
    const defaults = buildAmenityFormDefaults(null)
    expect(defaults.target_type).toBe('all')
    expect(defaults.target_unit_feature_ids).toEqual([])
    expect(defaults.target_tower_ids).toEqual([])
  })

  it('round-trips an existing amenity target_selector back into form fields', () => {
    const amenity: Partial<IAmenity> = {
      target_type: 'by_feature',
      target_selector: { unit_feature_ids: ['a', 'b'] },
    }
    const defaults = buildAmenityFormDefaults(amenity as IAmenity)
    expect(defaults.target_type).toBe('by_feature')
    expect(defaults.target_unit_feature_ids).toEqual(['a', 'b'])
  })

  it('round-trips a by_tower target_selector', () => {
    const amenity: Partial<IAmenity> = {
      target_type: 'by_tower',
      target_selector: { tower_ids: ['tower-1', 'tower-2'] },
    }
    const defaults = buildAmenityFormDefaults(amenity as IAmenity)
    expect(defaults.target_type).toBe('by_tower')
    expect(defaults.target_tower_ids).toEqual(['tower-1', 'tower-2'])
  })

  it('round-trips a manual target_selector', () => {
    const amenity: Partial<IAmenity> = {
      target_type: 'manual',
      target_selector: { unit_ids: ['unit-1'] },
    }
    const defaults = buildAmenityFormDefaults(amenity as IAmenity)
    expect(defaults.target_type).toBe('manual')
    expect(defaults.target_unit_ids).toEqual(['unit-1'])
  })
})
