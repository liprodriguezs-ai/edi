import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const userProfileFormSchema = z.object({
  user_id: z.string().min(1, 'La persona es requerida'),
  rfc: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || (v.trim().length >= 12 && v.trim().length <= 13), {
      message: 'El RFC debe tener 12 o 13 caracteres',
    }),
  curp: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || v.trim().length === 18, {
      message: 'El CURP debe tener 18 caracteres',
    }),
  birthdate: z.string().optional(),
  address: z.string().max(500).optional(),
  emergency_contact_name: z.string().max(150).optional(),
  emergency_contact_phone: z.string().max(20).optional(),
  notes: z.string().max(1000).optional(),
})

export interface IUserProfile extends IEntity {
  user_id: string
  rfc?: string | null
  curp?: string | null
  birthdate?: string | null
  address?: string | null
  emergency_contact_name?: string | null
  emergency_contact_phone?: string | null
  notes?: string | null
  user?: { id: string; name?: string; full_name?: string; email?: string | null; phone?: string | null } | null
}

export type UserProfileFormValues = z.infer<typeof userProfileFormSchema>
