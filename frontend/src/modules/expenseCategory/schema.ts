import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const expenseCategoryFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  parent_id: z.string().optional(),
  order: z.number().int().nullable(),
  is_active: z.boolean(),
})

export interface IExpenseCategory extends IEntity {
  name: string
  parent_id?: string | null
  order?: number | null
  is_active?: boolean
  parent?: { id: string; name: string } | null
}

export type ExpenseCategoryFormValues = z.infer<typeof expenseCategoryFormSchema>
