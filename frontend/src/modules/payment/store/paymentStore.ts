import type { IPayment } from '@/modules/payment/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const usePaymentStore = createEntityStore<IPayment>('payment')
