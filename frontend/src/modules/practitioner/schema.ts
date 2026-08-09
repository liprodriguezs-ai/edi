import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const practitionerFormSchema = z.object({
  user_id: z.string().min(1, 'El usuario es requerido'),
  license_number: z.string().min(1, 'La cédula profesional es requerida'),
  specialty: z.string().min(1, 'La especialidad es requerida'),
  health_center_id: z.string().min(1, 'El centro de salud es requerido'),
})

export interface IPractitionerProfile extends IEntity {
  user_id: string
  license_number: string
  specialty: string
  health_center_id: string
  user?: { id: string; name?: string } | null
  health_center?: { id: string; name: string } | null
}

export type PractitionerFormValues = z.infer<typeof practitionerFormSchema>
