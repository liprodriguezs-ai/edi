import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const stimulationGroupFormSchema = z
  .object({
    name: z.string().min(1, 'El nombre es requerido'),
    stimulation_group_type_id: z.string().min(1, 'El tipo de grupo es requerido'),
    health_center_id: z.string().min(1, 'El centro de salud es requerido'),
    promoter_user_id: z.string().min(1, 'La persona promotora es requerida'),
    start_date: z.string().min(1, 'La fecha de inicio es requerida'),
    end_date: z.string().optional(),
    is_active: z.boolean(),
  })
  .refine((v) => !v.end_date || v.end_date >= v.start_date, {
    message: 'La fecha de fin debe ser igual o posterior a la fecha de inicio',
    path: ['end_date'],
  })

export type StimulationGroupFormValues = z.infer<typeof stimulationGroupFormSchema>

export interface IStimulationGroup extends IEntity {
  name: string
  stimulation_group_type_id: string
  health_center_id: string
  promoter_user_id: string
  start_date: string
  end_date?: string | null
  is_active: boolean
  stimulation_group_type?: { id: string; name: string } | null
  health_center?: { id: string; name: string } | null
  promoter?: { id: string; name?: string } | null
}

export const memberFormSchema = z.object({
  stimulation_group_id: z.string().min(1),
  child_id: z.string().min(1, 'El niño es requerido'),
  default_attendance_payment_status_id: z.string().min(1, 'El estatus de pago es requerido'),
  enrolled_at: z.string().optional(),
})

export type MemberFormValues = z.infer<typeof memberFormSchema>

export interface IStimulationGroupMember extends IEntity {
  stimulation_group_id: string
  child_id: string
  enrolled_at: string
  default_attendance_payment_status_id: string
  child?: {
    id: string
    first_name: string
    last_name_paternal: string
    last_name_maternal?: string | null
  } | null
  default_attendance_payment_status?: { id: string; name: string } | null
}
