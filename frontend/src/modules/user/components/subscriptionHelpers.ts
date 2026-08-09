import type { BillingInterval } from '@/modules/checkoutSubscription/schema'

/**
 * Sufijo de intervalo de cobro. Fuente única compartida entre
 * MultiItemSubscriptionCard y ProrationPreviewPanel.
 */
export function intervalSuffix(interval: BillingInterval): string {
  return interval === 'year' ? '/año' : '/mes'
}
