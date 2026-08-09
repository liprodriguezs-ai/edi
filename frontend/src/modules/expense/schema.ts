import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { z } from 'zod'

export const EXPENSE_STATUS = {
  PAID: 'aa002278-0652-43e0-8333-a3cf47e5dadd',
  VOIDED: '1d92fd01-8b94-4ee5-9ebf-72ca90c86842',
} as const

export const BENEFICIARY_TYPE = {
  EMPLOYEE: '228511f6-e8fa-40e4-b64e-d4b91cb46c2e',
  PROVIDER: '32b02df7-bc4e-4b2b-beb3-aa831dd01d8f',
  OTHER: 'fa5eca75-833b-4a56-99da-72546cc5ffa9',
} as const

export interface IExpense extends IEntity {
  concept: string
  paid_at: string
  amount: IMoney | number
  expense_category_id: string
  beneficiary_type_id: string
  employee_id?: string | null
  provider_id?: string | null
  beneficiary_text?: string | null
  year: number
  month?: number | null
  payment_method_id: string
  bank_account_id: string
  reference?: string | null
  expense_status_id: string
  void_reason?: string | null
  notes?: string | null
  category?: { id: string; name: string } | null
  beneficiary_type?: { id: string; name: string } | null
  employee?: { id: string; name?: string; full_name?: string } | null
  provider?: { id: string; name?: string } | null
  bank_account?: { id: string; name?: string } | null
  payment_method?: { id: string; name: string } | null
  status?: { id: string; name: string } | null
}

/** amount del form en PESOS */
export const expenseFormSchema = z
  .object({
    concept: z.string().min(1, 'El concepto es requerido').max(255),
    description: z.string().optional(),
    paid_at: z.string().min(1, 'La fecha es requerida'),
    amount: z.number().positive('El monto debe ser mayor a 0'),
    expense_category_id: z.string().min(1, 'La categoría es requerida'),
    beneficiary_type_id: z.string().min(1, 'El tipo de beneficiario es requerido'),
    employee_id: z.string().optional(),
    provider_id: z.string().optional(),
    beneficiary_text: z.string().max(255).optional(),
    payment_method_id: z.string().min(1, 'El método es requerido'),
    bank_account_id: z.string().min(1, 'La cuenta es requerida'),
    reference: z.string().max(255).optional(),
    notes: z.string().optional(),
  })
  .superRefine((values, ctx) => {
    if (values.beneficiary_type_id === BENEFICIARY_TYPE.EMPLOYEE && !values.employee_id) {
      ctx.addIssue({ code: 'custom', path: ['employee_id'], message: 'Selecciona el empleado' })
    }
    if (values.beneficiary_type_id === BENEFICIARY_TYPE.PROVIDER && !values.provider_id) {
      ctx.addIssue({ code: 'custom', path: ['provider_id'], message: 'Selecciona el proveedor' })
    }
    if (values.beneficiary_type_id === BENEFICIARY_TYPE.OTHER && !values.beneficiary_text?.trim()) {
      ctx.addIssue({ code: 'custom', path: ['beneficiary_text'], message: 'Indica el beneficiario' })
    }
  })

export type ExpenseFormValues = z.infer<typeof expenseFormSchema>
