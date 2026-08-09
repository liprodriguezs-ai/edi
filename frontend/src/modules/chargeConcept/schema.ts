import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/**
 * Catálogos CENTRALES (UUIDs fijos) compartidos por el módulo de cobranza.
 * Fuente única — el módulo chargePolicy los reexporta desde aquí.
 */
export const AMOUNT_MODE = {
  FIXED_REQUIRED: '502ed9b1-befd-4d9e-87b0-37b42049646c',
  FIXED_OPTIONAL: '17eba951-dbfb-4662-9c18-f3b80f390001',
  RANGE: 'd02597fe-e700-4a86-a979-7970ad647fe7',
  FREE: '494676a2-3f68-4d90-a690-673c2f9c0623',
  BY_SHARE: '4918da56-6347-468c-8745-7d5d87c042d8',
} as const

export const PERIODICITY = {
  MONTHLY: '97b53d7a-48bf-47e5-b29b-865d17913253',
  BIMONTHLY: '582abd27-ded4-4e95-8ec3-9bdefed30904',
  YEARLY: 'a3605528-1321-41aa-ba8f-2a1d285dbc11',
  EVENTUAL: '2996f547-09d8-42e2-a448-69f4c2c2a477',
  /** Fixed UUID — must match Periodicity::WEEKLY constant in backend + CatalogSeeder. */
  WEEKLY: 'b1d3f4a2-5c6e-4f8a-9b0c-1d2e3f4a5b6c',
  /** Fixed UUID — must match Periodicity::BIWEEKLY constant in backend + CatalogSeeder. */
  BIWEEKLY: 'c2e4a5b3-6d7f-4a9b-8c1d-2e3f4a5b6c7d',
  /**
   * Fixed UUID — must match Periodicity::EVERY_TWO_DAYS constant in backend + CatalogSeeder.
   * Cadencia comprimida SOLO para QA: el backend la rechaza en producción.
   */
  EVERY_TWO_DAYS: '39a1ff2c-c536-4680-a854-a7f443033b51',
} as const

interface INamedCatalog {
  id: string
  name: string
}

/**
 * ChargeConcept es ahora SOLO la IDENTIDAD del cobrable (code/clave, nombre, categoría).
 * El COMPORTAMIENTO del cobro (monto, periodicidad, gracia, vigencia/alcance) vive en
 * `charge_policy` (módulo chargePolicy), 1:N por concepto.
 */
export interface IChargeConcept extends IEntity {
  /** Identificador único del concepto por clúster (UI lo llama "Clave", p.ej. MANT_MENSUAL). */
  code: string
  name: string
  description?: string
  charge_concept_category_id: string
  is_active?: boolean
  category?: INamedCatalog | null
}

export const chargeConceptFormSchema = z.object({
  code: z
    .string()
    .min(1, 'La clave es requerida')
    .regex(/^[A-Z0-9_]+$/, 'Solo mayúsculas, números y guión bajo'),
  name: z.string().min(1, 'El nombre es requerido').max(255),
  description: z.string().max(1000).optional(),
  charge_concept_category_id: z.string().min(1, 'La categoría es requerida'),
  is_active: z.boolean(),
})

export type ChargeConceptFormValues = z.infer<typeof chargeConceptFormSchema>
