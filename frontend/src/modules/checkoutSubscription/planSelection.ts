import type {
  BillingInterval,
  IBillingPlan,
  IPlanCatalog,
} from '@/modules/checkoutSubscription/schema'

/**
 * Lógica PURA del catálogo de planes (sin React, sin red) — se testea sola y la
 * comparten el armador público (`PlanBuilder`), el wizard de alta y el cambio de
 * plan del dueño.
 *
 * Ya NO hay sugerencia por número de unidades: el precio es fijo por banda y cada
 * tarjeta de plan muestra su rango, así que la grilla de planes es el único control.
 * El precio y la validación siguen siendo autoridad del backend.
 */

/**
 * ¿Es un plan de venta consultiva ("a medida")? El catálogo puede no traer la clave
 * (catálogos viejos/cacheados) → se trata como `false`.
 */
export function isContactSalesPlan(plan: IBillingPlan | null | undefined): boolean {
  return plan?.is_contact_sales === true
}

/** El plan de venta consultiva del catálogo, si existe. */
export function findContactSalesPlan(plans: IBillingPlan[]): IBillingPlan | null {
  return plans.find(isContactSalesPlan) ?? null
}

/**
 * ¿Se puede contratar este plan en línea AHORA MISMO? false solo cuando el plan
 * tiene precio de lista pero ninguna fila está sincronizada con Stripe — una
 * falla operativa (D6/D4), no una venta consultiva. Un plan de venta consultiva
 * nunca es "purchasable" (tiene su propio flujo de contacto). Ausente en
 * catálogos viejos/cacheados → se trata como `true` para no bloquear checkouts
 * legítimos durante el rollout.
 */
export function isPurchasablePlan(plan: IBillingPlan | null | undefined): boolean {
  if (!plan || isContactSalesPlan(plan)) return false
  return plan.is_purchasable !== false
}

/** Planes con precio de lista (todos menos el de venta consultiva). */
export function billablePlans(plans: IBillingPlan[]): IBillingPlan[] {
  return plans.filter((plan) => !isContactSalesPlan(plan))
}

/** Precio del PLAN en el intervalo dado, o null si no hay precio de lista. */
export function planPriceFor(
  plan: IBillingPlan | null,
  interval: BillingInterval
): { unit_amount: number; currency: string } | null {
  return plan?.prices?.[interval] ?? null
}

/**
 * Texto de la banda de unidades ("Ideal para 51–100 unidades"). El plan a medida
 * no tiene tope; un plan sin banda devuelve null y la UI simplemente no la pinta.
 *
 * La banda NO es decorativa: `suggested_unit_max` es el mismo número con el que el
 * backend rechaza registrar unidades de más (UnitLimitService), así que es el dato
 * que de verdad diferencia un plan de otro.
 */
export function planBandLabel(plan: IBillingPlan): string | null {
  if (plan.suggested_unit_min === null) return null
  if (plan.suggested_unit_max === null) {
    return `Desde ${plan.suggested_unit_min} unidades`
  }
  return `Ideal para ${plan.suggested_unit_min}–${plan.suggested_unit_max} unidades`
}

/**
 * Funciones incluidas en el plan: salen de sus módulos (hoy sólo el base). Se
 * deduplican por si el backend repite una entre módulos.
 *
 * OJO al pintarlas: en el modelo actual ("todo es base") TODOS los planes traen la
 * misma lista, así que mostrarla POR plan insinúa una diferencia que no existe.
 * Va una sola vez, como "esto viene en cualquier plan".
 */
export function planFeatures(plan: IBillingPlan | null): string[] {
  if (!plan) return []
  return [...new Set(plan.modules.flatMap((module) => module.features))]
}

/**
 * Funciones comunes a TODOS los planes cotizables, o null si algún plan trae una
 * lista distinta.
 *
 * En el modelo actual ("todo es base") los seis planes traen exactamente las mismas
 * funciones y sólo cambian banda y precio, así que la UI puede anunciarlas UNA vez
 * como "esto viene en cualquier plan". Si mañana un plan deja de traer algo, esto
 * devuelve null y la UI vuelve a listar por plan — el copy nunca miente por
 * quedarse viejo.
 */
export function sharedPlanFeatures(plans: IBillingPlan[]): string[] | null {
  if (plans.length === 0) return null

  const [first, ...rest] = plans
  const reference = planFeatures(first)
  if (reference.length === 0) return null

  const fingerprint = [...reference].sort().join('|')
  const allMatch = rest.every((plan) => [...planFeatures(plan)].sort().join('|') === fingerprint)

  return allMatch ? reference : null
}

/**
 * Plan cuya banda cubre `units`, o null si ninguna lo hace (caso "a medida" sin
 * tope ya entra por `suggested_unit_max === null`).
 *
 * Es una AYUDA de lectura, no la autoridad: quien decide si un cambio de plan es
 * válido sigue siendo el backend (`unit_limit_warning` del preview). Sirve para
 * que el dueño no tenga que comparar a mano su número de unidades contra seis
 * bandas.
 */
export function planForUnitCount(plans: IBillingPlan[], units: number): IBillingPlan | null {
  if (!Number.isFinite(units) || units <= 0) return null
  return (
    plans.find((plan) => {
      const min = plan.suggested_unit_min ?? 0
      const max = plan.suggested_unit_max
      return units >= min && (max === null || units <= max)
    }) ?? null
  )
}

/** Enlace `mailto:` de venta consultiva, o null si el catálogo no trae email. */
export function contactMailtoHref(catalog: IPlanCatalog | null): string | null {
  const email = catalog?.contact?.email
  if (!email) return null
  const subject = encodeURIComponent('Quiero un plan a medida para mi condominio')
  return `mailto:${email}?subject=${subject}`
}

/** Enlace de WhatsApp de venta consultiva, o null si el catálogo no trae número. */
export function contactWhatsappHref(catalog: IPlanCatalog | null): string | null {
  const whatsapp = catalog?.contact?.whatsapp
  if (!whatsapp) return null
  const digits = whatsapp.replace(/\D/g, '')
  if (digits.length === 0) return null
  const text = encodeURIComponent('Hola, quiero cotizar un plan a medida para mi condominio.')
  return `https://wa.me/${digits}?text=${text}`
}
