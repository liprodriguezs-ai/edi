import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { z } from 'zod'

export const BANK_ACCOUNT_TYPE = {
  BANK: '8ecf5da1-2d96-4fcf-815b-1202467856cf',
  CASH: '1b350e3c-1f75-4bda-bb9d-86093f5c2948',
  INVESTMENT: '797f5cec-6837-4a69-9126-685671a2fd92',
} as const

export interface IBankAccount extends IEntity {
  name: string
  bank_account_type_id: string
  bank_account_purpose_id?: string | null
  bank?: string | null
  account_number?: string | null
  clabe?: string | null
  card_number?: string | null
  currency?: string | null
  initial_balance?: IMoney | number | null
  current_balance?: IMoney | number | null
  is_primary?: boolean
  is_active?: boolean
  notes?: string | null
  type?: { id: string; name: string } | null
  purpose?: { id: string; name: string } | null
}

/** Deja solo dígitos: el usuario puede teclear la tarjeta con espacios o guiones. */
export const digitsOnly = (value: string): string => value.replace(/\D/g, '')

/** Muestra la tarjeta enmascarada (dato sensible): solo los últimos 4 dígitos. */
export const maskCardNumber = (value?: string | null): string => {
  const digits = digitsOnly(value ?? '')
  return digits.length >= 4 ? `•••• ${digits.slice(-4)}` : ''
}

/** initial_balance del form en PESOS */
export const bankAccountFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255),
  bank_account_type_id: z.string().min(1, 'El tipo es requerido'),
  bank_account_purpose_id: z.string().min(1, 'El propósito es requerido'),
  bank: z.string().max(100).optional(),
  account_number: z.string().max(50).optional(),
  clabe: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || /^\d{18}$/.test(v.trim()), {
      message: 'La CLABE debe tener 18 dígitos',
    }),
  card_number: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || /^\d{13,19}$/.test(digitsOnly(v)), {
      message: 'El número de tarjeta debe tener entre 13 y 19 dígitos',
    }),
  initial_balance: z.number().min(0).nullable(),
  is_primary: z.boolean(),
  is_active: z.boolean(),
  notes: z.string().max(1000).optional(),
})

export type BankAccountFormValues = z.infer<typeof bankAccountFormSchema>
