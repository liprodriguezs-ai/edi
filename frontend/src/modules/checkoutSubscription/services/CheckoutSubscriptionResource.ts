import { apiClient } from '@/shared/api/apiClient'
import {
  currentPlanSchema,
  multiItemSubscriptionSchema,
  stripeSubscriptionSchema,
  subscriptionChangePreviewSchema,
  trialsArraySchema,
  type BillingInterval,
  type IMultiItemMutationResult,
  type IMultiItemSubscription,
  type IMySubscription,
  type IStripeSubscription,
  type ISubscriptionChangePreview,
  type ISubscriptionPreviewRequest,
} from '@/modules/checkoutSubscription/schema'
import {
  preferentialSwapPreviewSchema,
  type IPreferentialSwapPreview,
} from '@/modules/tenant/billing/schema'

/**
 * Suscripción del condominio sobre los endpoints reales del backend:
 * - GET  /config/subscription              → datos de suscripción Stripe + bloque multi_item (billing v2)
 * - GET  /config/subscription/portal       → devuelve { url } del portal de facturación Stripe
 * - POST /config/subscription/cancel       → cancela la suscripción SIEMPRE al final del período (grace period)
 * - POST /config/subscription/resume       → reactiva una suscripción cancelada dentro del período de gracia
 *
 * Cambio de plan SELF-SERVE (owner), billing v2:
 * - POST /config/subscription/change-billing-plan/preview → prorrateo del cambio (sin aplicar; reutiliza PreferentialSwapService)
 * - POST /config/subscription/change-billing-plan         → aplica el cambio de plan; rechaza 422 si el plan destino no cubre un módulo contratado
 *
 * Gestión de la suscripción del dueño (todo va incluido en el plan; ya no hay
 * add/remove de módulos ni pruebas auto-servicio):
 * - POST /config/subscription/preview          → prorrateo del cambio propuesto (sin aplicar)
 * - POST /config/subscription/change-interval  → cambia el intervalo de cobro (mes ↔ año)
 */

// IStripeSubscription, IMultiItemMutationResult e IMySubscription viven en schema.ts.
// Se re-exportan aquí para no romper imports que pudieran quedar de versiones anteriores.
export type { IStripeSubscription, IMultiItemMutationResult, IMySubscription }

interface ConfigSubscription {
  status: string | null
  unit_count: number
  unit_limit: number | null
  subscription: IStripeSubscription | null
  multi_item: IMultiItemSubscription | null
  is_multi_item: boolean
  /**
   * Plan vigente del clúster sin importar la fuente (Stripe / manual / cortesía /
   * prueba). Puede faltar: se parsea con `safeParse` y se deriva de `multi_item`
   * cuando no llega.
   */
  current_plan?: unknown
  /** Fuente del acceso, si el backend la manda fuera de `current_plan`. */
  plan_source?: unknown
  /** Pruebas activas de módulos (billing v2). Ausente o vacío si no hay. */
  trials?: unknown
}

async function fetchConfigSubscription(): Promise<ConfigSubscription> {
  const { data } = await apiClient.get<{ data: ConfigSubscription }>('/config/subscription')
  return data.data
}

/**
 * Las mutaciones devuelven `{ message, data: <multi_item> }`. El bloque multi_item
 * se reconcilia de forma asíncrona vía webhook de Stripe, por eso se parsea con
 * `safeParse`: un hueco puntual en la forma nunca debe convertir una mutación
 * exitosa en un error — el mensaje del backend es lo que importa y la pantalla se
 * refresca aparte.
 */
function parseMutation(payload: { message: string; data: unknown }): IMultiItemMutationResult {
  const parsed = multiItemSubscriptionSchema.safeParse(payload.data)
  return { message: payload.message, data: parsed.success ? parsed.data : null }
}

export const CheckoutSubscriptionResource = {
  /** Devuelve la URL del portal de facturación de Stripe. */
  getPortalUrl: async (): Promise<string> => {
    const { data } = await apiClient.get<{ url: string }>('/config/subscription/portal')
    return data.url
  },

  /** Información de la suscripción activa del condominio (legado o multi-item). */
  getMySubscription: async (): Promise<IMySubscription> => {
    const result = await fetchConfigSubscription()
    // Validamos la forma del objeto subscription del backend con Zod (safeParse para no
    // romper la pantalla si el backend manda un campo inesperado).
    const parsedSubscription = stripeSubscriptionSchema
      .nullable()
      .safeParse(result.subscription ?? null)
    const parsedMultiItem = multiItemSubscriptionSchema
      .nullable()
      .safeParse(result.multi_item ?? null)
    // Pruebas activas: tolerante — si el campo falta o tiene forma inesperada, default [].
    const parsedTrials = trialsArraySchema.safeParse(
      Array.isArray(result.trials) ? result.trials : []
    )
    const multiItem = parsedMultiItem.success ? parsedMultiItem.data : null
    // Plan vigente sin importar la fuente. Si el backend aún no manda `current_plan`,
    // se deriva del bloque de Stripe para no perder información.
    const parsedCurrentPlan = currentPlanSchema.safeParse(result.current_plan)
    const currentPlan = parsedCurrentPlan.success
      ? {
          ...parsedCurrentPlan.data,
          source:
            parsedCurrentPlan.data.source ??
            (typeof result.plan_source === 'string' ? result.plan_source : null),
        }
      : multiItem?.plan
        ? {
            ...multiItem.plan,
            source: 'stripe',
            interval: multiItem.interval,
            total: multiItem.total,
            currency: multiItem.currency,
            expires_at: null,
          }
        : null
    return {
      // billing v2: el plan del owner sale del bloque multi_item, no de un plan legado.
      plan: multiItem?.plan ?? null,
      currentPlan,
      subscription: parsedSubscription.success ? parsedSubscription.data : null,
      multiItem,
      isMultiItem: result.is_multi_item ?? false,
      unitCount: result.unit_count ?? 0,
      unitLimit: result.unit_limit ?? null,
      trials: parsedTrials.success ? parsedTrials.data : [],
    }
  },

  /** Cancela la suscripción Stripe SIEMPRE al final del período de facturación. */
  cancelSubscription: async (): Promise<void> => {
    await apiClient.post('/config/subscription/cancel', {})
  },

  /** Reactiva (resume) una suscripción cancelada que aún está dentro del período de gracia. */
  resumeSubscription: async (): Promise<void> => {
    await apiClient.post('/config/subscription/resume', {})
  },

  /**
   * Vista previa del prorrateo de un cambio propuesto, SIN aplicarlo. Devuelve
   * `proration_date` para que el apply cobre exactamente lo previsto.
   */
  previewChange: async (
    payload: ISubscriptionPreviewRequest
  ): Promise<ISubscriptionChangePreview> => {
    const { data } = await apiClient.post<{ data: unknown }>(
      '/config/subscription/preview',
      payload
    )
    return subscriptionChangePreviewSchema.parse(data.data)
  },

  /** Cambia el intervalo de cobro (mes ↔ año), en cascada a todas las líneas. */
  changeInterval: async (
    interval: BillingInterval,
    prorationDate: number | null
  ): Promise<IMultiItemMutationResult> => {
    const { data } = await apiClient.post<{ message: string; data: unknown }>(
      '/config/subscription/change-interval',
      { interval, proration_date: prorationDate }
    )
    return parseMutation(data)
  },

  /**
   * Vista previa del prorrateo de un cambio de plan (owner self-serve). El backend
   * reutiliza PreferentialSwapService, por lo que la forma del resultado es idéntica
   * al swap preferencial de super-admin: puede incluir `manual: true` (pago manual)
   * o el desglose de líneas de Stripe (tarjeta).
   */
  previewBillingPlanChange: async (planKey: string): Promise<IPreferentialSwapPreview> => {
    const { data } = await apiClient.post<{ data: unknown }>(
      '/config/subscription/change-billing-plan/preview',
      { plan_key: planKey }
    )
    return preferentialSwapPreviewSchema.parse(data.data)
  },

  /**
   * Aplica el cambio de plan (owner self-serve). Rechaza 422 si el plan destino no
   * incluye algún módulo contratado por el clúster. `prorationDate` debe ser el valor
   * exacto del preview (para que el cobro coincida); null para clústeres con pago manual.
   */
  changeBillingPlan: async (
    planKey: string,
    prorationDate: number | null
  ): Promise<IMultiItemMutationResult> => {
    const body: Record<string, unknown> = { plan_key: planKey }
    if (prorationDate !== null) body.proration_date = prorationDate
    const { data } = await apiClient.post<{
      message: string
      data: { billing_package_id: string; subscription: unknown }
    }>('/config/subscription/change-billing-plan', body)
    const parsed = multiItemSubscriptionSchema.safeParse(data.data?.subscription)
    return { message: data.message, data: parsed.success ? parsed.data : null }
  },
}
