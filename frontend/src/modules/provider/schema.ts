import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { z } from 'zod'

export interface IProvider extends IEntity {
  name: string
  provider_type_id?: string | null
  provider_service_type_id?: string | null
  rfc?: string | null
  contact_person?: string | null
  phone?: string | null
  email?: string | null
  address?: string | null
  is_recurring?: boolean
  frequency?: string | null
  estimated_amount?: IMoney | number | null
  usual_payment_day?: number | null
  clabe?: string | null
  bank?: string | null
  notes?: string | null
  is_active?: boolean
  provider_type?: { id: string; name: string } | null
  service_type?: { id: string; name: string } | null
}

/** estimated_amount del form en PESOS */
export const providerFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255),
  provider_type_id: z.string().min(1, 'El tipo es requerido'),
  provider_service_type_id: z.string().min(1, 'El tipo de servicio es requerido'),
  rfc: z.string().optional(),
  contact_person: z.string().max(150).optional(),
  phone: z.string().min(1, 'El teléfono es requerido').max(20),
  email: z.string().email('Correo inválido').optional().or(z.literal('')),
  address: z.string().max(500).optional(),
  is_recurring: z.boolean(),
  estimated_amount: z.number().min(0).nullable(),
  usual_payment_day: z.number().int().min(1).max(31).nullable(),
  clabe: z.string().optional(),
  bank: z.string().max(100).optional(),
  notes: z.string().max(1000).optional(),
  is_active: z.boolean(),
})

export type ProviderFormValues = z.infer<typeof providerFormSchema>
