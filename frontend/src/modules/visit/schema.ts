import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const visitFormSchema = z.object({
  child_id: z.string().min(1, 'El niño es requerido'),
  health_center_id: z.string().min(1, 'El centro de salud es requerido'),
  practitioner_id: z.string().min(1, 'El profesional es requerido'),
  physical_receipt_number: z.string().optional(),
  visit_date: z.string().min(1, 'La fecha de la visita es requerida'),
})

export interface IVisit extends IEntity {
  child_id: string
  health_center_id: string
  practitioner_id: string
  /** Generado por el servidor (V-YYYY-NNNNNN), inmutable — nunca editable en el form. */
  receipt_number: string
  physical_receipt_number?: string | null
  visit_date: string
  child?: { id: string; first_name: string; last_name_paternal: string; last_name_maternal?: string | null } | null
  health_center?: { id: string; name: string } | null
  practitioner?: { id: string; user?: { id: string; name?: string } | null } | null
}

export type VisitFormValues = z.infer<typeof visitFormSchema>
