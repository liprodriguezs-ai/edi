import { QR_TYPE, visitorFormSchema, type VisitorFormValues } from '@/modules/access/schema'
import { describe, expect, it } from 'vitest'

const base = (overrides: Partial<VisitorFormValues> = {}): VisitorFormValues => ({
  unit_id: 'unit-1',
  name: 'Juan Visitante',
  visitor_motive_id: 'motive-1',
  qr_type_id: 'qr-1',
  arrives_by_vehicle: false,
  plate: '',
  vehicle_model: '',
  id_document_type: undefined,
  id_document_number: '',
  notes: '',
  ...overrides,
})

describe('visitorFormSchema', () => {
  it('accepts a pedestrian visit with the required fields', () => {
    expect(visitorFormSchema.safeParse(base()).success).toBe(true)
  })

  it('requires unit, name, motive and qr type', () => {
    for (const field of ['unit_id', 'name', 'visitor_motive_id', 'qr_type_id'] as const) {
      const result = visitorFormSchema.safeParse(base({ [field]: '' } as Partial<VisitorFormValues>))
      expect(result.success).toBe(false)
      if (!result.success) {
        expect(result.error.issues.some((i) => i.path.includes(field))).toBe(true)
      }
    }
  })

  it('requires a plate when arriving by vehicle', () => {
    const result = visitorFormSchema.safeParse(base({ arrives_by_vehicle: true, plate: '' }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('plate'))).toBe(true)
    }
  })

  it('accepts a vehicle visit when a plate is given', () => {
    expect(visitorFormSchema.safeParse(base({ arrives_by_vehicle: true, plate: 'ABC-123-4' })).success).toBe(true)
  })

  it('requires qr_expires_at for an EVENT qr type', () => {
    const result = visitorFormSchema.safeParse(base({ qr_type_id: QR_TYPE.EVENT, qr_expires_at: '' }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('qr_expires_at'))).toBe(true)
    }
  })

  it('rejects a past qr_expires_at for an EVENT qr type', () => {
    const pastDate = new Date(Date.now() - 60_000).toISOString()
    const result = visitorFormSchema.safeParse(base({ qr_type_id: QR_TYPE.EVENT, qr_expires_at: pastDate }))
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('qr_expires_at'))).toBe(true)
    }
  })

  it('accepts an EVENT qr type with a future qr_expires_at', () => {
    const futureDate = new Date(Date.now() + 3_600_000).toISOString()
    expect(visitorFormSchema.safeParse(base({ qr_type_id: QR_TYPE.EVENT, qr_expires_at: futureDate })).success).toBe(true)
  })

  it('does not require qr_expires_at for a TIME_BASED qr type', () => {
    expect(visitorFormSchema.safeParse(base({ qr_type_id: QR_TYPE.TIME_BASED, qr_expires_at: '' })).success).toBe(true)
  })
})
