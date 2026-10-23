import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/**
 * Agenda (odd/tasks/edi-agenda.md). La cita es administrativa; la visita
 * (encuentro clínico con folio) la crea el servidor al "Iniciar atención".
 * Estado y sellos de tiempo los deriva SIEMPRE el servidor.
 */
export const appointmentFormSchema = z.object({
  child_id: z.string().min(1, 'Selecciona al niño'),
  practitioner_id: z.string().min(1, 'Selecciona al profesional'),
  appointment_type_id: z.string().min(1, 'Selecciona el tipo de cita'),
  /** Hora de pared del tenant (`YYYY-MM-DDTHH:mm`); se convierte a UTC al enviar. */
  starts_at_local: z.string().min(1, 'Indica la fecha y hora de la cita'),
  duration_minutes: z
    .number({ error: 'Indica la duración en minutos' })
    .int('Usa minutos enteros')
    .min(5, 'Mínimo 5 minutos')
    .max(480, 'Máximo 8 horas'),
  notes: z.string().max(2000, 'Máximo 2000 caracteres').optional(),
  follow_up_id: z.string().optional(),
})

export type AppointmentFormValues = z.infer<typeof appointmentFormSchema>

/**
 * "Registrar sin cita": mismo formulario sin fecha ni duración (el servidor
 * usa "ahora" y la duración del tipo). Conserva la forma de los valores para
 * compartir el componente.
 */
export const walkInFormSchema = appointmentFormSchema.extend({
  starts_at_local: z.string(),
  duration_minutes: z.number(),
})

/** Claves del catálogo central appointment_status (el frontend ramifica por key, no por UUID). */
export type AppointmentStatusKey = 'scheduled' | 'confirmed' | 'arrived' | 'fulfilled' | 'cancelled' | 'no_show'

/** Claves del catálogo central appointment_type. */
export type AppointmentTypeKey = 'screening' | 'stimulation' | 'check_up'

export interface IAppointment extends IEntity {
  child_id: string
  health_center_id: string
  practitioner_id: string
  appointment_type_id: string
  appointment_status_id: string
  /** Instantes UTC (`...Z`). */
  starts_at: string
  ends_at: string
  is_walk_in: boolean
  confirmed_at?: string | null
  arrived_at?: string | null
  started_at?: string | null
  cancelled_at?: string | null
  cancellation_reason?: string | null
  no_show_at?: string | null
  visit_id?: string | null
  follow_up_id?: string | null
  notes?: string | null
  child?: {
    id: string
    first_name: string
    last_name_paternal: string
    last_name_maternal?: string | null
    birth_date?: string | null
  } | null
  practitioner?: { id: string; user?: { id: string; name?: string } | null } | null
  appointment_type?: { id: string; key: AppointmentTypeKey; name: string; default_duration_minutes: number } | null
  appointment_status?: { id: string; key: AppointmentStatusKey; name: string } | null
  visit?: { id: string; receipt_number: string } | null
}

/** Payload de POST /appointment (instante ya convertido a UTC). */
export interface AppointmentCreatePayload extends Record<string, unknown> {
  child_id: string
  practitioner_id: string
  appointment_type_id: string
  starts_at: string
  duration_minutes?: number
  notes?: string
  follow_up_id?: string
}

export interface AppointmentReschedulePayload {
  starts_at: string
  practitioner_id?: string
  duration_minutes?: number
}

export type AppointmentSuggestionReason = 'reassessment' | 'referral' | 'well_child'

/** GET /appointment/suggestion — sólo propone, nunca agenda. */
export interface IAppointmentSuggestion {
  suggested_date: string | null
  appointment_type_id: string
  reason_key: AppointmentSuggestionReason
  reason: string
  follow_up_id: string | null
}
