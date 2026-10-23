import type { BillingInterval } from '@/modules/checkoutSubscription/schema'
import type { SegmentedOption } from '@/shared/components/molecules/SegmentedControl'

/** Monthly / yearly choice shared by the checkout builder and the tenant wizard. */
export const BILLING_INTERVAL_OPTIONS: ReadonlyArray<SegmentedOption<BillingInterval>> = [
  { value: 'month', label: 'Mensual' },
  { value: 'year', label: 'Anual' },
]
