import { z } from 'zod'

/**
 * Modelo de facturación por PLAN (billing v2, "todo es base").
 *
 * El precio depende SOLO de la banda de unidades del PLAN: ya no se venden módulos
 * por separado — todo lo que existe va incluido en el plan base. La banda es un
 * atributo del plan (se muestra en su tarjeta), no un dato que el usuario capture;
 * para organizaciones por encima de `max_billable_units` existe el plan `a_medida`
 * (venta consultiva, `is_contact_sales`), elegible en la grilla. El CÁLCULO es
 * autoridad del backend; el frontend nunca calcula montos. Los montos llegan en
 * MINOR UNITS (centavos) → dividir entre 100 para pesos (usar `formatPrice`).
 *
 * Contrato:
 *  - GET  /billing/plan-catalog  (público)  → IPlanCatalog
 *  - POST /billing/plan-quote    (público)  → IPlanQuote   { plan_key, interval, modules? }
 *  - POST /billing/checkout      (auth)     → { url }      { plan_key, interval }
 *
 * `modules` quedó OPCIONAL en ambos cuerpos: si no viaja, el backend cotiza el plan
 * completo. El frontend ya no lo manda.
 */

export type BillingInterval = 'month' | 'year'

/**
 * NO hay lista de keys de plan en el frontend.
 *
 * Existió `BILLING_PLAN_KEYS` con las seis keys escritas a mano, y era una copia
 * del catálogo que el backend ya es dueño de: agregar un plan obligaba a editar
 * también este archivo, y olvidarlo hacía que `?plan=<nueva>` se ignorara en
 * silencio. La única autoridad sobre qué keys existen es el catálogo que sirve
 * `GET /billing/plan-catalog`, así que quien necesite validar una key la busca
 * ahí (`catalog.plans.find(...)`) en vez de contra una constante local.
 */

/**
 * Precio de un módulo dentro de un plan para un intervalo (centavos).
 *
 * INTERNO: el catálogo sigue trayendo `modules[]` y hay que parsearlo, pero ninguna
 * pantalla pinta líneas por módulo — por eso el schema no se exporta.
 */
const planModulePriceSchema = z.object({
  /** Monto por intervalo en minor units. 0 cuando `is_free`. */
  unit_amount: z.number(),
  /** true cuando el módulo se incluye sin costo en ese plan. */
  is_free: z.boolean(),
  currency: z.string(),
})

/**
 * Un módulo dentro de un plan, con su precio por intervalo. INTERNO: sólo alimenta
 * `billingPlanSchema.modules` (de donde salen las features del plan).
 */
const planModuleSchema = z.object({
  module_key: z.string(),
  name: z.string(),
  /** Descripción corta del módulo. Puede faltar en catálogos viejos/cacheados. */
  description: z.string().nullish(),
  /** El módulo base es obligatorio: siempre va incluido, no se puede quitar. */
  is_base: z.boolean(),
  /** Características a listar en la card. Puede faltar o venir vacía. */
  features: z.array(z.string()).default([]),
  prices: z
    .object({
      month: planModulePriceSchema.optional(),
      year: planModulePriceSchema.optional(),
    })
    .partial(),
})

/**
 * Precio del PLAN completo por intervalo. `prices` puede llegar VACÍO (`{}`) — es el
 * caso del plan `a_medida`, que no tiene precio de lista. NUNCA asumir que las claves
 * existen: siempre acceder con `prices?.[interval]`.
 */
export const planPriceSchema = z.object({
  /** Monto del plan por intervalo en minor units (centavos). */
  unit_amount: z.number(),
  currency: z.string(),
})

/** Un plan (basico..premium, a_medida) con su descripción y su precio de plan. */
export const billingPlanSchema = z.object({
  key: z.string(),
  name: z.string(),
  /** Descripción corta del plan. Puede faltar en catálogos viejos/cacheados. */
  description: z.string().nullish(),
  /** Banda de unidades sugerida. `suggested_unit_max: null` = sin tope (a medida). */
  suggested_unit_min: z.number().nullable(),
  suggested_unit_max: z.number().nullable(),
  /**
   * Plan de venta consultiva: no se cotiza ni se contrata en línea; la UI muestra
   * la card "Contáctanos". Ausente en catálogos previos al refactor → se trata
   * como `false` (ver `isContactSalesPlan`).
   */
  is_contact_sales: z.boolean().optional(),
  /**
   * false cuando el plan tiene precio de lista pero NINGUNA de sus filas está
   * sincronizada con Stripe (falla operativa — D6/D4). Distinto de
   * `is_contact_sales` (venta consultiva, price-less A PROPÓSITO). Ausente en
   * catálogos viejos/cacheados → se trata como `true` (ver `isPurchasablePlan`).
   */
  is_purchasable: z.boolean().optional(),
  modules: z.array(planModuleSchema),
  /**
   * Precio del PLAN por intervalo. Objeto SIEMPRE (`{}` cuando no hay precio de
   * lista); tolerante a la ausencia total de la clave.
   */
  prices: z
    .object({
      month: planPriceSchema.optional(),
      year: planPriceSchema.optional(),
    })
    .partial()
    .optional(),
})

/** Datos de contacto para la venta consultiva (plan a medida). */
export const planCatalogContactSchema = z.object({
  email: z.string().nullish(),
  /** Número de WhatsApp en formato internacional sin signos, o null si no hay. */
  whatsapp: z.string().nullish(),
})

export const planCatalogSchema = z.object({
  currency: z.string(),
  /** Tope de unidades de la banda de pago más alta. Por encima → plan a medida. */
  max_billable_units: z.number().nullish(),
  contact: planCatalogContactSchema.optional(),
  plans: z.array(billingPlanSchema),
})

/**
 * Una línea de la cotización. INTERNO: el backend las sigue devolviendo, pero la UI
 * muestra sólo el total del plan.
 */
const planQuoteLineSchema = z.object({
  module_key: z.string(),
  name: z.string(),
  /** Monto de la línea en minor units (0 si `is_free`). */
  unit_amount: z.number(),
  is_free: z.boolean(),
})

export const planQuoteSchema = z.object({
  plan: z.object({ key: z.string(), name: z.string() }),
  interval: z.enum(['month', 'year']),
  currency: z.string(),
  lines: z.array(planQuoteLineSchema),
  /** Total recurrente del plan + módulos elegidos (centavos). */
  total: z.number(),
})

export type IPlanPrice = z.infer<typeof planPriceSchema>
export type IPlanCatalogContact = z.infer<typeof planCatalogContactSchema>
export type IBillingPlan = z.infer<typeof billingPlanSchema>
export type IPlanCatalog = z.infer<typeof planCatalogSchema>
export type IPlanQuote = z.infer<typeof planQuoteSchema>

/**
 * Cuerpo de POST /billing/plan-quote. `modules` es OPCIONAL desde el refactor
 * "todo es base": si se omite, el backend cotiza el plan completo. El frontend
 * ya no lo envía; el campo se conserva por compatibilidad del contrato.
 */
export interface IPlanQuoteRequest {
  plan_key: string
  modules?: string[]
  interval: BillingInterval
}

/**
 * Cuerpo de POST /billing/checkout — el plan + intervalo, más metadata de tracking
 * opcional. El backend hila `event_id` en la metadata del Checkout Session de Stripe
 * para que el Purchase del webhook comparta identidad con el del navegador (dedup de
 * Meta).
 *
 * `units` existía como metadata informativa y ya NO se envía: el número de unidades
 * dejó de capturarse (la banda es un atributo del plan elegido). El backend lo tiene
 * como opcional.
 */
export interface IPlanCheckoutRequest extends IPlanQuoteRequest {
  event_id?: string
  /** GA4 client_id del navegador — se hila a la metadata de Stripe para que el Purchase del webhook comparta client_id con el del navegador. */
  ga_client_id?: string
}

/**
 * Suscripción MULTI-ITEM del dueño (un módulo base + N módulos en UNA sola
 * suscripción de Stripe). Es lo que devuelve GET /config/subscription en
 * `data.multi_item` cuando la organización factura por plan×módulo. Los montos llegan en
 * MINOR UNITS (centavos); el backend es la autoridad del cálculo, el frontend nunca
 * lo recalcula.
 */
const multiItemModuleSchema = z.object({
  key: z.string(),
  name: z.string(),
  /** El módulo base nunca se puede quitar. */
  is_base: z.boolean(),
  interval: z.enum(['month', 'year']),
  /** Subtotal de la línea (centavos). */
  subtotal: z.number(),
})

export const multiItemSubscriptionSchema = z.object({
  /** Plan bajo el que se contrató (basico..premium), o null si no se pudo resolver. */
  plan: z.object({ key: z.string(), name: z.string() }).nullable(),
  modules: z.array(multiItemModuleSchema),
  interval: z.enum(['month', 'year']),
  /** Total recurrente de la suscripción (centavos). */
  total: z.number(),
  currency: z.string(),
})

/**
 * Plan VIGENTE de la organización tal como lo reporta `GET /config/subscription` en
 * `current_plan`, INDEPENDIENTEMENTE de la fuente del acceso: Stripe, pago manual,
 * cortesía del super-admin o prueba.
 *
 * El bloque `multi_item` sólo describe suscripciones de Stripe, así que una organización
 * que paga por transferencia o tiene cortesía llega con `multi_item: null`. Sin este
 * bloque la pantalla lo trataba como "sin plan" y lo invitaba a contratar de nuevo.
 *
 * TODO menos `key`/`name` es tolerante: el backend puede no mandar la fuente ni el
 * monto todavía, y la pantalla debe seguir funcionando.
 */
export const currentPlanSchema = z.object({
  key: z.string(),
  name: z.string(),
  /** stripe | manual | super_admin_free | trial | legacy_plan. */
  source: z.string().nullish(),
  interval: z.string().nullish(),
  /** Total recurrente en minor units (centavos), si el backend lo conoce. */
  total: z.number().nullish(),
  currency: z.string().nullish(),
  /** Fin del acceso vigente (ISO 8601), o null si no vence (cortesía). */
  expires_at: z.string().nullish(),
})

export type ICurrentPlan = z.infer<typeof currentPlanSchema>

/**
 * Vista previa del prorrateo de un cambio propuesto — POST
 * /config/subscription/preview. `proration_amount` es FIRMADO: positivo = cargo
 * inmediato, negativo = crédito a favor. `proration_date` es el timestamp UNIX que
 * un apply debe fijar para cobrar exactamente lo previsto.
 */
export const subscriptionChangePreviewSchema = z.object({
  proration_amount: z.number(),
  proration_date: z.number().nullable(),
  /** Nuevo total recurrente del estado propuesto (centavos). */
  new_recurring_total: z.number(),
  currency: z.string(),
  /** Fecha efectiva del cambio (ISO 8601). */
  effective_at: z.string(),
})

export type IMultiItemSubscription = z.infer<typeof multiItemSubscriptionSchema>
export type ISubscriptionChangePreview = z.infer<typeof subscriptionChangePreviewSchema>

/**
 * Tipos de cambio auto-servicio sobre la suscripción del dueño. Ya no existen
 * `add_addon` / `remove_addon` (no hay módulos vendibles) y las unidades tampoco son
 * un cambio facturable, así que sólo queda el intervalo. Cambiar de PLAN va por su
 * propio endpoint (`change-billing-plan`).
 */
export type SubscriptionChangeType = 'change_interval'

/** Cuerpo de POST /config/subscription/preview. */
export interface ISubscriptionPreviewRequest {
  change_type: SubscriptionChangeType
  /** Requerido para change_interval. */
  interval?: BillingInterval
}

/**
 * Datos de suscripción Stripe tal como los devuelve el backend.
 * Se exporta desde aquí para que sea el punto único de verdad del tipo.
 */
export const stripeSubscriptionSchema = z.object({
  stripe_status: z.string(),
  trial_ends_at: z.string().nullable(),
  ends_at: z.string().nullable(),
  cancel_at_period_end: z.boolean(),
  current_period_end: z.string().nullable(),
  current_period_start: z.string().nullable(),
})
export type IStripeSubscription = z.infer<typeof stripeSubscriptionSchema>

/** Resultado de una mutación multi-item: mensaje del backend + estado resultante. */
export interface IMultiItemMutationResult {
  message: string
  data: IMultiItemSubscription | null
}

/**
 * Prueba activa de un módulo para el owner. Devuelta por GET /config/subscription
 * en `data.trials[]`, ordenada por vencimiento más cercano.
 */
export const trialSchema = z.object({
  module_key: z.string(),
  module_name: z.string(),
  plan_key: z.string(),
  plan_name: z.string(),
  /** ISO 8601 o null si la prueba no tiene fecha de fin. */
  expires_at: z.string().nullable(),
  /** Días restantes (0 = vence hoy, null = sin fecha de fin). */
  days_left: z.number().nullable(),
})

/** Array schema para parseo tolerante en el resource. */
export const trialsArraySchema = z.array(trialSchema)

export type IModuleTrial = z.infer<typeof trialSchema>

/** Suscripción tal como la consume la pantalla "Mi suscripción". */
export interface IMySubscription {
  /** Plan bajo el que se contrató (basico..premium), tomado del bloque multi_item (billing v2). */
  plan: { key: string; name: string } | null
  /**
   * Plan vigente sea cual sea la fuente (Stripe / manual / cortesía / prueba). Es lo
   * que permite distinguir "no tiene plan" de "tiene plan pero no por Stripe".
   * null cuando el backend no manda `current_plan` (contrato viejo) y no hay
   * suscripción multi-item de la que derivarlo.
   */
  currentPlan: ICurrentPlan | null
  subscription: IStripeSubscription | null
  multiItem: IMultiItemSubscription | null
  isMultiItem: boolean
  unitCount: number
  unitLimit: number | null
  /** Pruebas activas de módulos. Array vacío si no hay ninguna. */
  trials: IModuleTrial[]
}
