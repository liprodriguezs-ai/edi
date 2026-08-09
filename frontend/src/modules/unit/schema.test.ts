import { buildUnitFormSchema, type UnitFormValues } from '@/modules/unit/schema'
import { describe, expect, it } from 'vitest'

const APARTMENT = 'apartment-type-id'
const HOUSE = 'house-type-id'

const baseValues = (overrides: Partial<UnitFormValues> = {}): UnitFormValues => ({
  unit_type_id: HOUSE,
  number: '1',
  tower_id: undefined,
  floor: undefined,
  section_id: undefined,
  built_m2: null,
  land_m2: null,
  ownership_share: null,
  unit_status_id: 'active-status-id',
  notes: undefined,
  characteristics: [],
  ...overrides,
})

describe('buildUnitFormSchema', () => {
  // El flag requires_floor viaja en la fila del catálogo del tenant, no en UUIDs fijos.
  const requiresFloor = new Set([APARTMENT])

  it('accepts a house without tower/floor', () => {
    const result = buildUnitFormSchema(requiresFloor).safeParse(baseValues())
    expect(result.success).toBe(true)
  })

  it('rejects a vertical unit (requires_floor) without tower', () => {
    const result = buildUnitFormSchema(requiresFloor).safeParse(
      baseValues({ unit_type_id: APARTMENT, number: '101', floor: '1', tower_id: undefined }),
    )
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('tower_id'))).toBe(true)
    }
  })

  it('rejects a vertical unit without floor', () => {
    const result = buildUnitFormSchema(requiresFloor).safeParse(
      baseValues({ unit_type_id: APARTMENT, number: '101', tower_id: 'tower-1', floor: '' }),
    )
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('floor'))).toBe(true)
    }
  })

  it('accepts a vertical unit with tower and floor', () => {
    const result = buildUnitFormSchema(requiresFloor).safeParse(
      baseValues({ unit_type_id: APARTMENT, number: '101', tower_id: 'tower-1', floor: '1' }),
    )
    expect(result.success).toBe(true)
  })

  it('always requires a number', () => {
    const result = buildUnitFormSchema(requiresFloor).safeParse(baseValues({ number: '   ' }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('number'))).toBe(true)
    }
  })
})
