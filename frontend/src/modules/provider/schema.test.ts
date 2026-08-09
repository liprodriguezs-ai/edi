import { providerFormSchema, type ProviderFormValues } from '@/modules/provider/schema'
import { describe, expect, it } from 'vitest'

const baseValues = (overrides: Partial<ProviderFormValues> = {}): ProviderFormValues => ({
  name: 'Jardinería del Valle',
  provider_type_id: 'type-id',
  provider_service_type_id: 'service-id',
  rfc: undefined,
  contact_person: undefined,
  phone: '5512345678',
  email: '',
  address: undefined,
  is_recurring: false,
  estimated_amount: null,
  usual_payment_day: null,
  clabe: undefined,
  bank: undefined,
  notes: undefined,
  is_active: true,
  ...overrides,
})

describe('providerFormSchema', () => {
  it('accepts a valid provider', () => {
    expect(providerFormSchema.safeParse(baseValues()).success).toBe(true)
  })

  it('requires phone (backend ProviderCreateRequest marks it required)', () => {
    const result = providerFormSchema.safeParse(baseValues({ phone: '' }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('phone'))).toBe(true)
    }
  })

  it('rejects an invalid email but allows empty string', () => {
    expect(providerFormSchema.safeParse(baseValues({ email: 'not-an-email' })).success).toBe(false)
    expect(providerFormSchema.safeParse(baseValues({ email: '' })).success).toBe(true)
  })
})
