/**
 * Tests para los schemas Zod del módulo package.
 * Valida las reglas de negocio codificadas en packageFormSchema y packagePickupSchema
 * sin necesidad de montar componentes ni mockear red.
 */
import {
  packageBatchSchema,
  packageFormSchema,
  packagePickupSchema,
} from '@/modules/package/schema'
import { describe, expect, it } from 'vitest'

// ─── packageFormSchema ────────────────────────────────────────────────────────

describe('packageFormSchema', () => {
  it('acepta una recepción mínima válida (solo unit_id)', () => {
    const result = packageFormSchema.safeParse({ unit_id: 'uuid-unit-1' })
    expect(result.success).toBe(true)
  })

  it('rechaza unit_id vacío', () => {
    const result = packageFormSchema.safeParse({ unit_id: '' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('unit_id'))).toBe(true)
    }
  })

  it('acepta campos opcionales como undefined', () => {
    const result = packageFormSchema.safeParse({
      unit_id: 'uuid-unit-1',
      package_carrier_id: undefined,
      carrier_other: undefined,
      tracking_number: undefined,
      description: undefined,
      storage_location: undefined,
    })
    expect(result.success).toBe(true)
  })

  it('rechaza carrier_other mayor a 100 caracteres', () => {
    const result = packageFormSchema.safeParse({
      unit_id: 'uuid-unit-1',
      carrier_other: 'a'.repeat(101),
    })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('carrier_other'))).toBe(true)
    }
  })

  it('rechaza description mayor a 500 caracteres', () => {
    const result = packageFormSchema.safeParse({
      unit_id: 'uuid-unit-1',
      description: 'x'.repeat(501),
    })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('description'))).toBe(true)
    }
  })

  it('rechaza tracking_number mayor a 100 caracteres', () => {
    const result = packageFormSchema.safeParse({
      unit_id: 'uuid-unit-1',
      tracking_number: 'T'.repeat(101),
    })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('tracking_number'))).toBe(true)
    }
  })
})

// ─── packageBatchSchema ───────────────────────────────────────────────────────

describe('packageBatchSchema', () => {
  const validItem = { unit_id: 'uuid-unit-1' }

  it('acepta un lote con un ítem válido', () => {
    const result = packageBatchSchema.safeParse({ packages: [validItem] })
    expect(result.success).toBe(true)
  })

  it('rechaza un lote vacío', () => {
    const result = packageBatchSchema.safeParse({ packages: [] })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('packages'))).toBe(true)
    }
  })

  it('rechaza un lote de más de 50 ítems', () => {
    const packages = Array.from({ length: 51 }, () => ({ ...validItem }))
    const result = packageBatchSchema.safeParse({ packages })
    expect(result.success).toBe(false)
  })

  it('acepta un lote de exactamente 50 ítems', () => {
    const packages = Array.from({ length: 50 }, () => ({ ...validItem }))
    const result = packageBatchSchema.safeParse({ packages })
    expect(result.success).toBe(true)
  })

  it('rechaza un ítem sin unit_id', () => {
    const result = packageBatchSchema.safeParse({ packages: [{ unit_id: '' }] })
    expect(result.success).toBe(false)
  })
})

// ─── packagePickupSchema ──────────────────────────────────────────────────────

describe('packagePickupSchema', () => {
  const validUuid = '123e4567-e89b-12d3-a456-426614174000'

  it('acepta un array de UUIDs válidos', () => {
    const result = packagePickupSchema.safeParse({ package_ids: [validUuid] })
    expect(result.success).toBe(true)
  })

  it('rechaza package_ids vacío', () => {
    const result = packagePickupSchema.safeParse({ package_ids: [] })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('package_ids'))).toBe(true)
    }
  })

  it('rechaza más de 20 IDs', () => {
    const ids = Array.from({ length: 21 }, (_, i) => `123e4567-e89b-12d3-a456-${String(i).padStart(12, '0')}`)
    const result = packagePickupSchema.safeParse({ package_ids: ids })
    expect(result.success).toBe(false)
  })

  it('acepta exactamente 20 IDs', () => {
    const ids = Array.from({ length: 20 }, (_, i) => `123e4567-e89b-12d3-a456-${String(i).padStart(12, '0')}`)
    const result = packagePickupSchema.safeParse({ package_ids: ids })
    expect(result.success).toBe(true)
  })

  it('rechaza un ID que no sea UUID', () => {
    const result = packagePickupSchema.safeParse({ package_ids: ['not-a-uuid'] })
    expect(result.success).toBe(false)
  })

  it('acepta campos de proxy opcionales', () => {
    const result = packagePickupSchema.safeParse({
      package_ids: [validUuid],
      pickup_proxy_name: 'Juan Pérez',
      pickup_proxy_relationship: 'Familiar',
    })
    expect(result.success).toBe(true)
  })
})
