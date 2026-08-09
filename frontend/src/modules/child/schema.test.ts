import { childFormSchema, childFullName } from '@/modules/child/schema'
import { describe, expect, it } from 'vitest'

const validPayload = {
  first_name: 'Sofía',
  last_name_paternal: 'García',
  last_name_maternal: 'López',
  birth_date: '2022-03-05',
  sex_id: 'ed100001-0000-4000-8000-000000000002',
  health_center_id: '11111111-1111-1111-1111-111111111111',
  is_schooled: false,
}

describe('childFormSchema', () => {
  it('accepts a valid payload', () => {
    expect(childFormSchema.safeParse(validPayload).success).toBe(true)
  })

  // D-13: single-surname registrations must be accepted by the frontend too.
  it('accepts a missing last_name_maternal', () => {
    const { last_name_maternal: _omitted, ...payload } = validPayload
    expect(childFormSchema.safeParse(payload).success).toBe(true)
  })

  it('rejects a missing first_name', () => {
    const { first_name: _omitted, ...payload } = validPayload
    const result = childFormSchema.safeParse(payload)
    expect(result.success).toBe(false)
  })

  it('rejects a curp with fewer than 18 characters', () => {
    const result = childFormSchema.safeParse({ ...validPayload, curp: 'GASO18051' })
    expect(result.success).toBe(false)
  })

  it('accepts an 18-character curp', () => {
    const result = childFormSchema.safeParse({ ...validPayload, curp: 'GASO180514MDFRPN09' })
    expect(result.success).toBe(true)
  })
})

describe('childFullName', () => {
  it('joins all three name parts when present', () => {
    expect(childFullName(validPayload)).toBe('Sofía García López')
  })

  it('omits the maternal surname when absent (D-13)', () => {
    expect(
      childFullName({ first_name: 'Sofía', last_name_paternal: 'García', last_name_maternal: null })
    ).toBe('Sofía García')
  })

  it('omits the maternal surname when it is an empty string', () => {
    expect(
      childFullName({ first_name: 'Sofía', last_name_paternal: 'García', last_name_maternal: '' })
    ).toBe('Sofía García')
  })
})
