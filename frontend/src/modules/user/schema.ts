import type { IRole } from '@/modules/role/schema'
import type { ITenant } from '@/modules/tenant/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { PASSWORD_MIN_LENGTH, PASSWORD_REGEX } from '@/shared/constants/passwordRequirements'
import { z } from 'zod'

const passwordOptional = z
  .string()
  .optional()
  .or(z.literal(''))
  .refine(
    (val) => !val || val.length >= PASSWORD_MIN_LENGTH,
    `La contraseña debe tener al menos ${PASSWORD_MIN_LENGTH} caracteres`
  )
  .refine(
    (val) => !val || PASSWORD_REGEX.test(val),
    'Debe incluir mayúscula, minúscula y número'
  )

export const userFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  last_name: z.string().min(1, 'El apellido paterno es requerido'),
  second_last_name: z.string().optional(),
  phone: z.string().optional(),
  email: z.string().email('El correo debe ser válido'),
  password: passwordOptional,
  password_confirmation: z.string().optional().or(z.literal('')),
  role_id: z.string().min(1, 'El rol es requerido'),
}).refine((data) => {
  if (data.password && data.password.length > 0) {
    return data.password === data.password_confirmation
  }
  return true
}, {
  message: 'Las contraseñas no coinciden',
  path: ['password_confirmation'],
})

export interface IUser extends IEntity {
  roles?: IRole[]
  role?: IRole
  name: string
  last_name?: string
  second_last_name?: string
  full_name?: string
  phone?: string
  email: string
  email_verified_at?: string
  profile_image_url?: string
  tenant_id?: string
  role_id?: string
  tenants?: ITenant[]
}

export type UserFormValues = z.infer<typeof userFormSchema>
