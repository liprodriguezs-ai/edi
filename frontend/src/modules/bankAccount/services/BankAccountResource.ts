import type { IBankAccount } from '@/modules/bankAccount/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const BankAccountResource = createCrudApi<IBankAccount, Record<string, unknown>>({
  basePath: '/bank_account',
})
