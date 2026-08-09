import { buildAmenityPayload } from '@/modules/amenity/buildAmenityPayload'
import { RESERVATION_MODE, type AmenityFormValues } from '@/modules/amenity/schema'
import { describe, expect, it } from 'vitest'

const baseValues = (overrides: Partial<AmenityFormValues> = {}): AmenityFormValues => ({
  name: 'Cancha de tenis',
  description: '',
  restrictions: '',
  capacity: 4,
  available_weekdays: [1, 2, 3],
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
  reservation_mode_id: RESERVATION_MODE.BY_SLOT,
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

describe('buildAmenityPayload', () => {
  it('converts cost_amount from pesos to minor units when has_cost is true', () => {
    const payload = buildAmenityPayload(
      baseValues({ has_cost: true, cost_amount: 150.5, bank_account_id: 'bank-1' })
    )
    expect(payload.cost_amount).toBe(15050)
    expect(payload.bank_account_id).toBe('bank-1')
  })

  it('nulls cost_amount and bank_account_id when has_cost is false', () => {
    const payload = buildAmenityPayload(
      baseValues({ has_cost: false, cost_amount: 150.5, bank_account_id: 'bank-1' })
    )
    expect(payload.cost_amount).toBeNull()
    expect(payload.bank_account_id).toBeNull()
  })

  it('converts deposit_amount from pesos to minor units when requires_deposit is true', () => {
    const payload = buildAmenityPayload(
      baseValues({ requires_deposit: true, deposit_amount: 300, deposit_due_days: 5 })
    )
    expect(payload.deposit_amount).toBe(30000)
    expect(payload.deposit_due_days).toBe(5)
  })

  it('nulls deposit fields when requires_deposit is false', () => {
    const payload = buildAmenityPayload(
      baseValues({ requires_deposit: false, deposit_amount: 300, deposit_due_days: 5 })
    )
    expect(payload.deposit_amount).toBeNull()
    expect(payload.deposit_due_days).toBeNull()
  })

  it('nulls out_of_service_reason when out_of_service is false', () => {
    const payload = buildAmenityPayload(
      baseValues({ out_of_service: false, out_of_service_reason: 'Mantenimiento' })
    )
    expect(payload.out_of_service_reason).toBeNull()
  })

  it('keeps out_of_service_reason when out_of_service is true', () => {
    const payload = buildAmenityPayload(
      baseValues({ out_of_service: true, out_of_service_reason: 'Mantenimiento' })
    )
    expect(payload.out_of_service_reason).toBe('Mantenimiento')
  })

  it('passes through identity fields unchanged', () => {
    const payload = buildAmenityPayload(baseValues())
    expect(payload.name).toBe('Cancha de tenis')
    expect(payload.capacity).toBe(4)
    expect(payload.available_weekdays).toEqual([1, 2, 3])
    expect(payload.reservation_mode_id).toBe(RESERVATION_MODE.BY_SLOT)
  })

  describe('target_type / target_selector mapping', () => {
    it('maps target_type=all to a null selector', () => {
      const payload = buildAmenityPayload(baseValues({ target_type: 'all' }))
      expect(payload.target_type).toBe('all')
      expect(payload.target_selector).toBeNull()
    })

    it('maps target_type=by_type to unit_type_ids', () => {
      const payload = buildAmenityPayload(
        baseValues({ target_type: 'by_type', target_unit_type_ids: ['type-1', 'type-2'] })
      )
      expect(payload.target_type).toBe('by_type')
      expect(payload.target_selector).toEqual({ unit_type_ids: ['type-1', 'type-2'] })
    })

    it('maps target_type=by_tower to tower_ids', () => {
      const payload = buildAmenityPayload(
        baseValues({ target_type: 'by_tower', target_tower_ids: ['tower-1'] })
      )
      expect(payload.target_selector).toEqual({ tower_ids: ['tower-1'] })
    })

    it('maps target_type=by_section to section_ids', () => {
      const payload = buildAmenityPayload(
        baseValues({ target_type: 'by_section', target_section_ids: ['section-1'] })
      )
      expect(payload.target_selector).toEqual({ section_ids: ['section-1'] })
    })

    it('maps target_type=by_feature to unit_feature_ids (AND semantics, resolved server-side)', () => {
      const payload = buildAmenityPayload(
        baseValues({
          target_type: 'by_feature',
          target_unit_feature_ids: ['feature-1', 'feature-2'],
        })
      )
      expect(payload.target_selector).toEqual({ unit_feature_ids: ['feature-1', 'feature-2'] })
    })

    it('maps target_type=manual to unit_ids', () => {
      const payload = buildAmenityPayload(
        baseValues({ target_type: 'manual', target_unit_ids: ['unit-1', 'unit-2'] })
      )
      expect(payload.target_selector).toEqual({ unit_ids: ['unit-1', 'unit-2'] })
    })

    it('maps target_type=with_vehicles to a null selector (resolved server-side, like all)', () => {
      const payload = buildAmenityPayload(baseValues({ target_type: 'with_vehicles' }))
      expect(payload.target_selector).toBeNull()
    })
  })
})
