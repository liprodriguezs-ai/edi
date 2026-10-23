import { z } from 'zod'

/**
 * Contrato de facturación de un tenant (super-admin) — billing v2 (por módulo).
 *
 * Todos los montos llegan en MINOR UNITS (centavos). Para mostrarlos en pesos se
 * dividen entre 100 (usar `formatPrice`). El cálculo SIEMPRE es autoridad del
 * backend; el frontend sólo recoge entradas y muestra lo que devuelve la API.
 *
 * Endpoints (super-admin, gate tokenCan update.tenant || *, sin initializeTenancy):
 *  - GET   /tenant/{id}/billing                       → IBillingSummary
 *  - PATCH /tenant/{id}/payment-method                → cambia método (cancela sub si aplica)
 *  - POST  /tenant/{id}/subscription-payment          → registra pago manual + extiende ciclo
 *  - GET   /tenant/{id}/subscription-payment          → ledger del tenant
 *  - POST  /tenant/{id}/entitlement/grant-free        → activa módulo gratis perpetuo
 *  - POST  /tenant/{id}/entitlement/revoke            → revoca módulo
 *  - POST  /tenant/{id}/preferential-swap/preview     → prorrateo (card) o aviso (manual)
 *  - POST  /tenant/{id}/preferential-swap             → aplica el cambio de plan
 */

/** Método de cobro del tenant. `card` = Stripe (automático); el resto es manual. */
export type BillingPaymentMethod = 'card' | 'transfer' | 'cash'

/** Métodos válidos para registrar un pago manual (no Stripe). */
export type ManualPaymentMethod = 'transfer' | 'cash'

/** Momento en que se cancela la suscripción Stripe al pasar de tarjeta a manual. */
export type CancelMode = 'at_period_end' | 'now'

export type BillingInterval = 'month' | 'year'

/**
 * Origen de un entitlement: pagado, cortesía perpetua, etc.
 *
 * `amount` deriva del PLAN del módulo (precio según el plan asignado + intervalo):
 *  - cortesía (`super_admin_free`) → `amount: 0`
 *  - precio mal configurado/desconocido → `amount: null` (nunca 500 en el back)
 */
export const billingEntitlementSchema = z.object({
  /** Clave del módulo facturado (null si el módulo fue eliminado). */
  module_key: z.string().nullable(),
  /** Nombre legible del módulo. */
  module_name: z.string().nullable(),
  /** Clave del plan bajo el que se otorgó el acceso. */
  plan_key: z.string().nullable(),
  /** Nombre legible del plan. */
  plan_name: z.string().nullable(),
  source: z.string(),
  interval: z.string().nullable(),
  /** Precio derivado del plan en minor units (centavos). null = sin precio configurado. */
  amount: z.number().nullable(),
  expires_at: z.string().nullable(),
})

// ---------------------------------------------------------------------------
// Catálogo de planes disponibles (available_plans)
// ---------------------------------------------------------------------------

/** Precio de un módulo en un plan para un intervalo concreto. */
const billingPlanModulePriceSchema = z.object({
  unit_amount: z.number(),
  is_free: z.boolean(),
  currency: z.string(),
})

/** Entrada de módulo dentro de un plan del catálogo. */
const billingPlanModuleSchema = z.object({
  module_key: z.string(),
  name: z.string(),
  is_base: z.boolean(),
  /** Precios por intervalo ('month' | 'year'). */
  prices: z.record(z.string(), billingPlanModulePriceSchema).optional(),
})

/** Un plan del catálogo (público u oculto/preferencial) con su matriz de precios. */
export const billingAvailablePlanSchema = z.object({
  key: z.string(),
  name: z.string(),
  /**
   * Visibilidad del plan. `false` = variante OCULTA/preferencial, la única que el
   * swap preferencial acepta como destino.
   *
   * OBLIGATORIA: `available_plans` sale de `PlanPricingService::buildPlanCatalog()`,
   * que la emite siempre (`'is_public' => (bool) $plan->is_public`). Estuvo
   * `.optional()` para tolerar respuestas viejas, y esa tolerancia sostenía una
   * heurística en `isPreferentialPlan` que adivinaba la visibilidad comparando la
   * key contra una lista escrita a mano en el frontend. Exigirla aquí es lo que
   * permite borrar esa adivinanza: si algún día faltara, preferimos un error de
   * parseo visible a una clasificación silenciosamente equivocada.
   */
  is_public: z.boolean(),
  suggested_unit_min: z.number().nullable().optional(),
  suggested_unit_max: z.number().nullable().optional(),
  modules: z.array(billingPlanModuleSchema),
})

// ---------------------------------------------------------------------------
// Catálogo de módulos disponibles (available_modules)
// ---------------------------------------------------------------------------

/** Módulo facturable activo. No lleva precios — éstos viven en los planes. */
export const billingAvailableModuleSchema = z.object({
  key: z.string(),
  name: z.string(),
  /** Módulo base: el plan "piso" de la organización. */
  is_base: z.boolean(),
})

// ---------------------------------------------------------------------------
// Ledger
// ---------------------------------------------------------------------------

export const billingLedgerEntrySchema = z.object({
  /** Módulo al que corresponde el pago (null si fue eliminado). */
  module_key: z.string().nullable(),
  /** Plan bajo el que se facturó (null si fue eliminado). */
  plan_key: z.string().nullable(),
  amount: z.number(),
  currency: z.string(),
  method: z.string(),
  paid_at: z.string().nullable(),
  period_end: z.string().nullable(),
  notes: z.string().nullable(),
})

// ---------------------------------------------------------------------------
// Pago manual sugerido
// ---------------------------------------------------------------------------

/**
 * Una línea del pago manual sugerido: un módulo PAGADO de la organización con su precio
 * derivado del plan. El back excluye cortesía (super_admin_free) y Stripe.
 */
export const billingManualPaymentLineSchema = z.object({
  module_key: z.string(),
  name: z.string(),
  plan_key: z.string(),
  interval: z.string().nullable(),
  /** Precio sugerido en minor units (centavos). null = sin precio, capturar a mano. */
  amount: z.number().nullable(),
})

/**
 * Pago manual sugerido: UN pago que cubre TODOS los módulos contratados de pago.
 * `lines` = una por módulo pagado (con su precio del plan); `total` = autosuma.
 */
export const billingManualPaymentSchema = z.object({
  lines: z.array(billingManualPaymentLineSchema),
  total: z.number(),
})

// ---------------------------------------------------------------------------
// Resumen completo de facturación
// ---------------------------------------------------------------------------

export const billingSummarySchema = z.object({
  payment_method: z.enum(['card', 'transfer', 'cash']),
  status: z.string(),
  /** Plan de facturación actual del tenant, o null si no tiene ninguno asignado. */
  plan: z.object({ key: z.string(), name: z.string() }).nullable(),
  entitlements: z.array(billingEntitlementSchema),
  /** Catálogo de módulos facturables activos (sin precios — los precios están en los planes). */
  available_modules: z.array(billingAvailableModuleSchema),
  /** Catálogo de planes (incluyendo ocultos/preferenciales) con sus matrices de precios. */
  available_plans: z.array(billingAvailablePlanSchema),
  manual_payment: billingManualPaymentSchema,
  ledger: z.array(billingLedgerEntrySchema),
  ledger_total: z.number(),
})

// ---------------------------------------------------------------------------
// Preview del cambio de plan preferencial
// ---------------------------------------------------------------------------

/**
 * Preview del cambio a plan preferencial.
 * - CARD: trae `proration_date`, `amount_due`, `currency` y `lines`.
 * - MANUAL: trae `manual: true`, `message` y `new_price`.
 * Se modela como objeto plano (no unión discriminada) para tolerar ambas formas.
 */
const swapPreviewLineSchema = z
  .object({
    description: z.string().nullable().optional(),
    amount: z.number().optional(),
  })
  .passthrough()

/**
 * Aviso de plan-downgrade-guard (plan-downgrade-guard): presente solo cuando el
 * conteo de unidades activas de la organización no cabe en el plan destino. `.optional()`
 * es DELIBERADO — el objeto padre NO es `.strict()`, así que Zod hace STRIP por
 * default con las claves que faltan; nunca convertir a `.strict()`/`z.strictObject`,
 * ambos consumidores (CheckoutSubscriptionResource, TenantBillingResource) comparten
 * este schema y uno de los dos nunca envía esta clave.
 */
const unitLimitWarningSchema = z.object({
  message: z.string(),
  plan_name: z.string().nullable(),
  unit_limit: z.number().nullable(),
  active_units: z.number(),
  suggested_plan_name: z.string().nullable(),
})

export const preferentialSwapPreviewSchema = z.object({
  manual: z.boolean().optional(),
  message: z.string().optional(),
  new_price: z.number().optional(),
  /** Unix timestamp (segundos) que Stripe eligió para el prorrateo, o null en manual. */
  proration_date: z.number().int().nullable().optional(),
  amount_due: z.number().optional(),
  currency: z.string().optional(),
  lines: z.array(swapPreviewLineSchema).optional(),
  unit_limit_warning: unitLimitWarningSchema.optional(),
})

// ---------------------------------------------------------------------------
// Tipos exportados
// ---------------------------------------------------------------------------

export type IBillingEntitlement = z.infer<typeof billingEntitlementSchema>
export type IBillingAvailableModule = z.infer<typeof billingAvailableModuleSchema>
export type IBillingAvailablePlan = z.infer<typeof billingAvailablePlanSchema>
export type IBillingManualPaymentLine = z.infer<typeof billingManualPaymentLineSchema>
export type IBillingManualPayment = z.infer<typeof billingManualPaymentSchema>
export type IBillingLedgerEntry = z.infer<typeof billingLedgerEntrySchema>
export type IBillingSummary = z.infer<typeof billingSummarySchema>
export type IPreferentialSwapPreviewLine = z.infer<typeof swapPreviewLineSchema>
export type IPreferentialSwapPreview = z.infer<typeof preferentialSwapPreviewSchema>

// ---------------------------------------------------------------------------
// Form schemas — pago manual
// ---------------------------------------------------------------------------

/**
 * Form de registro de pago manual: método, fecha y notas opcionales.
 * Las líneas NO son editables — se derivan directamente de `IBillingManualPayment.lines`
 * al momento de enviar (importes en minor units sin modificar).
 */
export const manualPaymentFormSchema = z.object({
  method: z.enum(['transfer', 'cash'], { message: 'Elige el método de pago' }),
  paid_at: z.string().min(1, 'Elige la fecha en que se recibió el pago'),
  notes: z.string().optional(),
})

export type ManualPaymentFormValues = z.infer<typeof manualPaymentFormSchema>

// ---------------------------------------------------------------------------
// Cuerpos de peticiones (lo que viaja al backend, ya en centavos)
// ---------------------------------------------------------------------------

export interface IChangePaymentMethodRequest {
  payment_method: BillingPaymentMethod
  cancel_mode?: CancelMode
}

/**
 * Petición para activar acceso gratis (grant-free) o revocar un módulo.
 * `plan_key` es opcional: el backend lo resuelve desde el plan actual del tenant
 * si se omite.
 */
export interface IEntitlementRequest {
  module_key: string
  plan_key?: string | null
}

/** Una línea de la petición de pago manual (ya en centavos). */
export interface IRegisterPaymentLine {
  module_key: string
  plan_key: string
  /** minor units (centavos). */
  amount: number
  /** Opcional: cae al intervalo del entitlement, luego mensual. */
  interval?: string | null
}

/** UN pago manual que cubre varios módulos. Los montos viajan en centavos. */
export interface IRegisterPaymentRequest {
  method: ManualPaymentMethod
  paid_at: string
  notes?: string
  lines: IRegisterPaymentLine[]
}

/**
 * Petición para el swap de plan preferencial. El backend resuelve los módulos
 * contratados del tenant desde sus entitlements activos; sólo se necesita el
 * plan destino.
 */
export interface IPreferentialSwapRequest {
  to_plan_key: string
  /** Se reenvía EXACTO (Unix timestamp en segundos) el valor del preview para que el cobro coincida. */
  proration_date?: number
}
