import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/**
 * UUIDs del catálogo CENTRAL unit_relationship (espejo del modelo backend).
 * unit_type y unit_status son tenant-scoped: sus filas se cargan por catálogo
 * y el comportamiento viaja en flags (requires_floor / is_billable), nunca
 * en UUIDs fijos.
 */
export const UNIT_RELATIONSHIP = {
  OWNER: '0a8e0380-19c4-47e2-a7f1-2a1dfaa2c087',
  CO_OWNER: 'b116d845-0e52-449f-9402-00ed6230d8f8',
  RENTER: '023ebb8f-5f06-43cf-8964-bd1659e5d369',
  FAMILY_RESIDENT: 'fc6ed62b-09fc-4720-87ff-5e4e20de4cdf',
  BILLING_CONTACT: '647259b2-f599-4648-af69-592260db4251',
} as const

interface INamedCatalog {
  id: string
  name: string
  color?: string | null
  requires_floor?: boolean
  is_billable?: boolean
}

export interface IUnitVehicle extends IEntity {
  unit_id: string
  plate: string
  make?: string | null
  model?: string | null
  color?: string | null
  year?: number | null
}

export interface IUnitUser extends IEntity {
  unit_id: string
  user_id: string
  unit_relationship_id: string
  is_billing_owner?: boolean
  is_legal_representative?: boolean
  start_date?: string | null
  end_date?: string | null
  notes?: string | null
  unit?: IUnit | null
  unit_relationship?: INamedCatalog | null
  user?: { id: string; name?: string; email?: string | null; phone?: string | null } | null
}

export interface IUnit extends IEntity {
  unit_type_id: string
  number?: string | null
  street?: string | null
  interior_number?: string | null
  tower_id?: string | null
  floor?: string | null
  section_id?: string | null
  built_m2?: string | number | null
  land_m2?: string | number | null
  ownership_share?: string | number | null
  unit_status_id: string
  notes?: string | null
  characteristics?: string[] | null
  full_identifier?: string
  unit_type?: INamedCatalog | null
  unit_status?: INamedCatalog | null
  tower?: INamedCatalog | null
  section?: INamedCatalog | null
  vehicles?: IUnitVehicle[]
  unit_users?: IUnitUser[]
}

const unitFormBaseSchema = z.object({
  unit_type_id: z.string().min(1, 'El tipo es requerido'),
  number: z.string().max(50, 'Máximo 50 caracteres').optional(),
  street: z.string().max(150, 'Máximo 150 caracteres').optional(),
  interior_number: z.string().max(50, 'Máximo 50 caracteres').optional(),
  tower_id: z.string().optional(),
  floor: z.string().max(20).optional(),
  section_id: z.string().optional(),
  built_m2: z.number().min(0).max(9999.99).nullable(),
  land_m2: z.number().min(0).max(9999.99).nullable(),
  ownership_share: z.number().min(0).max(100).nullable(),
  unit_status_id: z.string().min(1, 'El estado es requerido'),
  notes: z.string().max(1000, 'Máximo 1000 caracteres').optional(),
  characteristics: z.array(z.string()).optional(),
})

/**
 * §6.3.3: los tipos con requires_floor (verticales) exigen torre + piso.
 * El set de ids se arma desde el catálogo unit_type cargado en el form —
 * el flag viaja en la fila del catálogo del tenant, no en UUIDs fijos.
 */
export const buildUnitFormSchema = (requiresFloorIds: ReadonlySet<string>) =>
  unitFormBaseSchema.superRefine((values, ctx) => {
    if (requiresFloorIds.has(values.unit_type_id)) {
      if (!values.tower_id) {
        ctx.addIssue({
          code: 'custom',
          path: ['tower_id'],
          message: 'La torre es requerida para este tipo de unidad',
        })
      }
      if (!values.floor?.trim()) {
        ctx.addIssue({
          code: 'custom',
          path: ['floor'],
          message: 'El piso es requerido para este tipo de unidad',
        })
      }
    }
    if (!values.number?.trim()) {
      ctx.addIssue({ code: 'custom', path: ['number'], message: 'El número es requerido' })
    }
  })

export type UnitFormValues = z.infer<typeof unitFormBaseSchema>

export const unitUserFormSchema = z.object({
  user_id: z.string().min(1, 'Selecciona una persona de la lista'),
  unit_relationship_id: z.string().min(1, 'El rol es requerido'),
  is_billing_owner: z.boolean(),
  is_legal_representative: z.boolean(),
  start_date: z.string().min(1, 'La fecha de inicio es requerida'),
  end_date: z.string().optional(),
  notes: z.string().max(1000).optional(),
})

export type UnitUserFormValues = z.infer<typeof unitUserFormSchema>

export const changeOwnerSchema = z.object({
  user_id: z.string().min(1, 'Selecciona al nuevo propietario de la lista'),
  change_date: z.string().optional(),
  notes: z.string().max(1000).optional(),
  confirmation: z.string().refine((v) => v.trim() === 'CAMBIAR', {
    message: 'Escribe CAMBIAR para confirmar',
  }),
})

export type ChangeOwnerValues = z.infer<typeof changeOwnerSchema>
