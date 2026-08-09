import type { IExpenseCategory } from '@/modules/expenseCategory/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useExpenseCategoryStore = createEntityStore<IExpenseCategory>('expenseCategory')
