import type { IBillingEntitlement } from '@/modules/tenant/billing/schema'

/** Moneda por defecto del panel de facturación. Los montos llegan en centavos. */
export const BILLING_CURRENCY = 'MXN'

const INTERVAL_LABELS: Record<string, string> = {
  month: 'mes',
  year: 'año',
}

/** Traduce el intervalo de cobro a una etiqueta corta en español (default: mes). */
export function billingIntervalLabel(interval: string | null | undefined): string {
  if (!interval) {
    return 'mes'
  }
  return INTERVAL_LABELS[interval] ?? interval
}

/** Un entitlement está vigente si no tiene vencimiento o si vence en el futuro. */
export function isActiveEntitlement(entitlement: IBillingEntitlement): boolean {
  if (!entitlement.expires_at) {
    return true
  }
  return new Date(entitlement.expires_at).getTime() >= Date.now()
}
