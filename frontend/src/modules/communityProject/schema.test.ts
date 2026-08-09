import {
  PARTICIPATION_TYPE,
  communityProjectEditSchema,
  communityProjectFormSchema,
  contributeFormSchema,
  reportPaymentFormSchema,
  type CommunityProjectEditValues,
  type CommunityProjectFormValues,
  type ContributeFormValues,
  type ReportPaymentFormValues,
} from '@/modules/communityProject/schema'
import { describe, expect, it } from 'vitest'

const base = (overrides: Partial<ContributeFormValues> = {}): ContributeFormValues => ({
  amount: 250,
  payment_method_id: 'method-1',
  bank_account_id: 'account-1',
  paid_at: '2026-06-11',
  reference: '',
  ...overrides,
})

describe('contributeFormSchema', () => {
  it('accepts a valid contribution', () => {
    expect(contributeFormSchema.safeParse(base()).success).toBe(true)
  })

  it('rejects a zero or negative amount', () => {
    expect(contributeFormSchema.safeParse(base({ amount: 0 })).success).toBe(false)
    expect(contributeFormSchema.safeParse(base({ amount: -10 })).success).toBe(false)
  })

  it('requires payment method, bank account and date', () => {
    for (const field of ['payment_method_id', 'bank_account_id', 'paid_at'] as const) {
      const result = contributeFormSchema.safeParse(
        base({ [field]: '' } as Partial<ContributeFormValues>)
      )
      expect(result.success).toBe(false)
      if (!result.success) {
        expect(result.error.issues.some((issue) => issue.path.includes(field))).toBe(true)
      }
    }
  })
})

const reportBase = (overrides: Partial<ReportPaymentFormValues> = {}): ReportPaymentFormValues => ({
  amount: 250,
  payment_method_id: 'method-1',
  paid_at: '2026-06-11',
  reference: '',
  ...overrides,
})

describe('reportPaymentFormSchema', () => {
  it('accepts a valid report (no bank account)', () => {
    expect(reportPaymentFormSchema.safeParse(reportBase()).success).toBe(true)
  })

  it('rejects a zero or negative amount', () => {
    expect(reportPaymentFormSchema.safeParse(reportBase({ amount: 0 })).success).toBe(false)
    expect(reportPaymentFormSchema.safeParse(reportBase({ amount: -10 })).success).toBe(false)
  })

  it('requires payment method and date', () => {
    for (const field of ['payment_method_id', 'paid_at'] as const) {
      const result = reportPaymentFormSchema.safeParse(
        reportBase({ [field]: '' } as Partial<ReportPaymentFormValues>)
      )
      expect(result.success).toBe(false)
      if (!result.success) {
        expect(result.error.issues.some((issue) => issue.path.includes(field))).toBe(true)
      }
    }
  })
})

const projectBase = (
  overrides: Partial<CommunityProjectFormValues> = {}
): CommunityProjectFormValues => ({
  title: 'Pintura de fachadas',
  description_html: '<p>Detalle</p>',
  community_project_category_id: 'cat-1',
  participation_type_id: PARTICIPATION_TYPE.VOLUNTARY,
  goal_amount: null,
  ideal_amount: 100,
  min_amount: null,
  allow_decline: true,
  start_date: '2026-07-01',
  due_date: '',
  visible_to_residents: true,
  payments_visible_to_residents: false,
  allow_comments: true,
  ...overrides,
})

describe('communityProjectFormSchema', () => {
  it('accepts a valid voluntary project with the privacy flag', () => {
    expect(communityProjectFormSchema.safeParse(projectBase()).success).toBe(true)
    expect(
      communityProjectFormSchema.safeParse(projectBase({ payments_visible_to_residents: true }))
        .success
    ).toBe(true)
  })

  it('treats the rich description as optional', () => {
    const { description_html: _omit, ...withoutDescription } = projectBase()
    expect(communityProjectFormSchema.safeParse(withoutDescription).success).toBe(true)
  })

  it('requires the ideal amount for voluntary projects', () => {
    const result = communityProjectFormSchema.safeParse(projectBase({ ideal_amount: null }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((issue) => issue.path.includes('ideal_amount'))).toBe(true)
    }
  })

  it('allows a mandatory project without an ideal amount', () => {
    const result = communityProjectFormSchema.safeParse(
      projectBase({ participation_type_id: PARTICIPATION_TYPE.MANDATORY, ideal_amount: null })
    )
    expect(result.success).toBe(true)
  })

  it('requires the title', () => {
    expect(communityProjectFormSchema.safeParse(projectBase({ title: '' })).success).toBe(false)
  })
})

const editBase = (
  overrides: Partial<CommunityProjectEditValues> = {}
): CommunityProjectEditValues => ({
  title: 'Pintura de fachadas 2026',
  description_html: '<p>Nueva descripción</p>',
  visible_to_residents: true,
  payments_visible_to_residents: false,
  ...overrides,
})

describe('communityProjectEditSchema', () => {
  it('accepts a valid restricted edit', () => {
    expect(communityProjectEditSchema.safeParse(editBase()).success).toBe(true)
  })

  it('treats the rich description as optional', () => {
    const { description_html: _omit, ...withoutDescription } = editBase()
    expect(communityProjectEditSchema.safeParse(withoutDescription).success).toBe(true)
  })

  it('requires the title', () => {
    expect(communityProjectEditSchema.safeParse(editBase({ title: '' })).success).toBe(false)
  })

  it('rejects unknown structural fields via the typed contract (goal_amount is stripped)', () => {
    const parsed = communityProjectEditSchema.safeParse({ ...editBase(), goal_amount: 5000 })
    expect(parsed.success).toBe(true)
    if (parsed.success) {
      expect('goal_amount' in parsed.data).toBe(false)
    }
  })
})
