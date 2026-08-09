import type { IBankAccount } from '@/modules/bankAccount/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useBankAccountStore = createEntityStore<IBankAccount>('bankAccount')
