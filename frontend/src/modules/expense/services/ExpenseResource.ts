import type { IExpense } from '@/modules/expense/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import type { IMoney } from '@/shared/utils/money'

export interface PublicSummary {
  year: number
  month: number
  total_income: IMoney
  total_expenses: IMoney
  net_balance: IMoney
  expenses_by_category: Array<{ category: string; total: IMoney }>
  bank_account_balances?: Array<{ name: string; current_balance: IMoney }>
}

export const ExpenseResource = createCrudApi<
  IExpense,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    void: (_id: string, _reason: string) => Promise<IExpense>
    uploadMedia: (_id: string, _file: File) => Promise<IExpense>
    publicSummary: (_year?: number, _month?: number) => Promise<PublicSummary>
  }
>({
  basePath: '/expense',
  customMethods: () => ({
    void: async (id, reason) => {
      const { data } = await apiClient.post<{ data: IExpense }>(`/expense/${id}/void`, { reason })
      return data.data
    },
    uploadMedia: async (id, file) => {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await apiClient.post<{ data: IExpense }>(`/expense/${id}/media`, formData)
      return data.data
    },
    publicSummary: async (year, month) => {
      const { data } = await apiClient.get<{ data: PublicSummary }>('/treasury/public-summary', {
        params: { year, month },
      })
      return data.data
    },
  }),
})
