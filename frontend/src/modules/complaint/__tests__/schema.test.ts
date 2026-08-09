/**
 * Tests para los schemas Zod del módulo complaint.
 * Valida las reglas de negocio codificadas en complaintFormSchema y sus
 * schemas de acciones (mensaje/transición/asignación) sin montar componentes
 * ni mockear red.
 */
import {
  complaintAssignSchema,
  complaintFormSchema,
  complaintMessageSchema,
  complaintTransitionSchema,
} from '@/modules/complaint/schema'
import { describe, expect, it } from 'vitest'

// ─── complaintFormSchema ────────────────────────────────────────────────────

describe('complaintFormSchema', () => {
  const validBase = {
    title: 'Ruido excesivo',
    complaint_category_id: 'uuid-category-1',
    complaint_priority_id: 'uuid-priority-1',
    against_description: 'Vecino con música alta después de medianoche',
  }

  it('acepta una incidencia mínima válida sin unidad señalada', () => {
    const result = complaintFormSchema.safeParse(validBase)
    expect(result.success).toBe(true)
  })

  it('acepta against_unit_id opcional', () => {
    const result = complaintFormSchema.safeParse({ ...validBase, against_unit_id: 'uuid-unit-1' })
    expect(result.success).toBe(true)
  })

  it('rechaza title vacío', () => {
    const result = complaintFormSchema.safeParse({ ...validBase, title: '' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('title'))).toBe(true)
    }
  })

  it('rechaza title mayor a 150 caracteres', () => {
    const result = complaintFormSchema.safeParse({ ...validBase, title: 'a'.repeat(151) })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('title'))).toBe(true)
    }
  })

  it('rechaza complaint_category_id vacío', () => {
    const result = complaintFormSchema.safeParse({ ...validBase, complaint_category_id: '' })
    expect(result.success).toBe(false)
  })

  it('rechaza complaint_priority_id vacío', () => {
    const result = complaintFormSchema.safeParse({ ...validBase, complaint_priority_id: '' })
    expect(result.success).toBe(false)
  })

  it('rechaza against_description vacío', () => {
    const result = complaintFormSchema.safeParse({ ...validBase, against_description: '' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('against_description'))).toBe(true)
    }
  })

  it('rechaza against_description mayor a 2000 caracteres', () => {
    const result = complaintFormSchema.safeParse({
      ...validBase,
      against_description: 'x'.repeat(2001),
    })
    expect(result.success).toBe(false)
  })
})

// ─── complaintMessageSchema ─────────────────────────────────────────────────

describe('complaintMessageSchema', () => {
  it('acepta un mensaje válido', () => {
    const result = complaintMessageSchema.safeParse({ body: 'Se contactó a mantenimiento.' })
    expect(result.success).toBe(true)
  })

  it('rechaza body vacío', () => {
    const result = complaintMessageSchema.safeParse({ body: '' })
    expect(result.success).toBe(false)
  })

  it('rechaza body mayor a 2000 caracteres', () => {
    const result = complaintMessageSchema.safeParse({ body: 'x'.repeat(2001) })
    expect(result.success).toBe(false)
  })
})

// ─── complaintTransitionSchema ──────────────────────────────────────────────

describe('complaintTransitionSchema', () => {
  it('acepta un complaint_status_id válido', () => {
    const result = complaintTransitionSchema.safeParse({ complaint_status_id: 'uuid-status-1' })
    expect(result.success).toBe(true)
  })

  it('rechaza complaint_status_id vacío', () => {
    const result = complaintTransitionSchema.safeParse({ complaint_status_id: '' })
    expect(result.success).toBe(false)
  })
})

// ─── complaintAssignSchema ──────────────────────────────────────────────────

describe('complaintAssignSchema', () => {
  it('acepta un assigned_user_id válido', () => {
    const result = complaintAssignSchema.safeParse({ assigned_user_id: 'uuid-user-1' })
    expect(result.success).toBe(true)
  })

  it('rechaza assigned_user_id vacío', () => {
    const result = complaintAssignSchema.safeParse({ assigned_user_id: '' })
    expect(result.success).toBe(false)
  })
})
