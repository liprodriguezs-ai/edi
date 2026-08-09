import type { IExpense } from '@/modules/expense/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useExpenseStore = createEntityStore<IExpense>('expense')
