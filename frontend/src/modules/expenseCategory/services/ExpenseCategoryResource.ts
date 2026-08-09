import type { ExpenseCategoryFormValues, IExpenseCategory } from '@/modules/expenseCategory/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ExpenseCategoryResource = createCrudApi<IExpenseCategory, ExpenseCategoryFormValues>({
  basePath: '/expense_category',
})
