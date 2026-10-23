import {
  followUpCancelFormSchema,
  followUpChildName,
  followUpFormSchema,
  followUpUpdateFormSchema,
  referralFormSchema,
} from '@/modules/followUp/schema'
import { describe, expect, it } from 'vitest'

describe('followUpFormSchema', () => {
  it('accepts a valid manual-create payload', () => {
    const result = followUpFormSchema.safeParse({
      screening_id: 'screening-1',
      follow_up_type_id: 'type-1',
      due_date: '2026-09-01',
    })

    expect(result.success).toBe(true)
  })

  it('rejects a payload missing required fields', () => {
    const result = followUpFormSchema.safeParse({ screening_id: '', follow_up_type_id: '', due_date: '' })

    expect(result.success).toBe(false)
  })
})

describe('followUpUpdateFormSchema', () => {
  it('requires a due_date', () => {
    expect(followUpUpdateFormSchema.safeParse({ due_date: '2026-09-01' }).success).toBe(true)
    expect(followUpUpdateFormSchema.safeParse({ due_date: '' }).success).toBe(false)
  })
})

describe('followUpCancelFormSchema', () => {
  it('requires a non-empty reason', () => {
    expect(followUpCancelFormSchema.safeParse({ reason: 'Ya no aplica' }).success).toBe(true)
    expect(followUpCancelFormSchema.safeParse({ reason: '' }).success).toBe(false)
  })
})

describe('referralFormSchema', () => {
  it('requires follow_up_id, referral_specialty_id and referred_at', () => {
    const valid = referralFormSchema.safeParse({
      follow_up_id: 'fu-1',
      referral_specialty_id: 'spec-1',
      referred_at: '2026-09-01',
    })
    expect(valid.success).toBe(true)

    const invalid = referralFormSchema.safeParse({
      follow_up_id: 'fu-1',
      referral_specialty_id: '',
      referred_at: '',
    })
    expect(invalid.success).toBe(false)
  })
})

describe('followUpChildName', () => {
  it('joins the present name parts', () => {
    expect(
      followUpChildName({ id: 'c1', first_name: 'Ana', last_name_paternal: 'Pérez', last_name_maternal: 'López' })
    ).toBe('Ana Pérez López')
  })

  it('falls back to an em dash when child is missing', () => {
    expect(followUpChildName(null)).toBe('—')
    expect(followUpChildName(undefined)).toBe('—')
  })
})
