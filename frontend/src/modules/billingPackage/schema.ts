import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

// ─── Entidad CRUD del plan de facturación ────────────────────────────────────

export const billingPackageFormSchema = z
  .object({
    key: z
      .string()
      .min(1, 'La clave es requerida')
      .regex(
        /^[a-z][a-z0-9_-]*$/,
        'Solo minúsculas, números, guión y guión bajo; debe empezar con letra'
      ),
    name: z.string().min(1, 'El nombre es requerido'),
    description: z.string().optional().nullable(),
    is_public: z.boolean().default(false),
    is_active: z.boolean().default(true),
    sort_order: z.coerce.number().int().min(0).default(0),
    suggested_unit_min: z.preprocess(
      (v) => (v === '' || v == null ? null : v),
      z.coerce.number().int().min(1).nullable()
    ),
    suggested_unit_max: z.preprocess(
      (v) => (v === '' || v == null ? null : v),
      z.coerce.number().int().min(1).nullable()
    ),
  })
  .refine(
    (d) =>
      d.suggested_unit_min == null ||
      d.suggested_unit_max == null ||
      d.suggested_unit_min <= d.suggested_unit_max,
    { message: 'El máximo debe ser mayor o igual al mínimo', path: ['suggested_unit_max'] }
  )

export interface IBillingPackage extends IEntity {
  key: string
  name: string
  // Note: description is inherited from IEntity as `string | undefined`; do NOT redeclare as `string | null`
  is_public: boolean
  is_active: boolean
  sort_order: number
  suggested_unit_min?: number | null
  suggested_unit_max?: number | null
  plan_prices?: IPlanPrice[]
}

export type BillingPackageFormValues = z.infer<typeof billingPackageFormSchema>

// ─── Celda de la matriz de precios (un precio × módulo × intervalo) ─────────
// is_free/trial_days NO viven aquí: son un hecho de (plan, módulo), no de
// intervalo — ver IPlanPriceMatrixModule.

export interface IPlanPriceCell {
  id: string
  unit_amount: number // minor units (centavos)
  currency: string
  is_active: boolean
  stripe_price_id: string | null
}

// ─── Módulo billable con sus celdas (mes y/o año) ──────────────────────────

export interface IPlanPriceMatrixModule {
  billable_module_id: string
  key: string
  name: string
  is_base: boolean
  is_free: boolean
  trial_days: number | null
  cells: {
    month: IPlanPriceCell | null
    year: IPlanPriceCell | null
  }
}

// ─── Respuesta completa del GET /billing/plan/{planId}/price-matrix ──────────

export interface IPlanPriceMatrix {
  plan: {
    id: string
    key: string
    name: string
  }
  intervals: string[]
  /** Fallback de plataforma cuando trial_days es null en una celda */
  default_trial_days: number
  modules: IPlanPriceMatrixModule[]
}

// ─── Body del PUT /billing/plan/{planId}/price-matrix (v2) ──────────────────
// Un IPlanPriceMatrixModuleInput por módulo: los flags van UNA vez (no por
// celda), cells solo lleva montos.

export interface IPlanPriceCellInput {
  unit_amount: number // minor units (centavos)
  currency?: string
}

export interface IPlanPriceMatrixModuleInput {
  billable_module_id: string
  is_free: boolean
  /** null = sin override: aplica el default de plataforma (default_trial_days) */
  trial_days: number | null
  cells: {
    month: IPlanPriceCellInput
    year: IPlanPriceCellInput
  }
}

// ─── Relación plan_prices (include de la lista) ──────────────────────────────

export interface IPlanPrice extends IEntity {
  billable_module_id: string
  interval: string
  unit_amount: number
  currency: string
  is_free: boolean
  is_active: boolean
  trial_days: number | null
  stripe_price_id?: string | null
}
