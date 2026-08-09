import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

interface INamed {
  id: string
  name: string
}

export interface IComplaintStatus extends IEntity {
  name: string
  key?: string | null
  color?: string | null
}

export interface IComplaintMessage extends IEntity {
  complaint_id: string
  user_id: string
  body: string
  author?: { id: string; name?: string } | null
}

export interface IComplaint extends IEntity {
  case_number: string
  title: string
  complaint_category_id: string
  complaint_priority_id: string
  complaint_status_id: string
  reporter_user_id: string
  assigned_user_id?: string | null
  against_unit_id?: string | null
  against_description: string
  resolved_at?: string | null
  /** Tenant's complaint.resolution_days_target, appended on show() (module-config §12) — drives the aging chip. */
  resolution_days_target?: number | null
  // Relations
  category?: INamed | null
  priority?: (INamed & { color?: string | null }) | null
  status?: IComplaintStatus | null
  reporter?: { id: string; name?: string } | null
  assignee?: { id: string; name?: string } | null
  against_unit?: { id: string; number?: string | null; full_identifier?: string } | null
  messages?: IComplaintMessage[]
}

/** Un cambio de campo dentro de una entrada del historial (ver IComplaintHistoryEntry). */
export interface IComplaintHistoryChange {
  field: string
  label: string
  from: string | number | null
  to: string | number | null
}

/**
 * Entrada del historial de cambios (GET /complaint/{id}/history), leída del
 * trail de Laravel Auditing ya existente y traducida server-side (nombres de
 * catálogo/usuario en vez de UUIDs crudos). `changes` viene vacío cuando
 * `event === 'created'` — el frontend renderiza ese caso como el hito fijo
 * "Incidencia registrada" sin listar el diff completo.
 */
export interface IComplaintHistoryEntry {
  id: string
  event: 'created' | 'updated' | 'deleted' | 'restored'
  user_name: string
  created_at: string | null
  changes: IComplaintHistoryChange[]
}

/**
 * Contrato de GET/PUT /config/complaint-settings (keys PLANAS en el wire; el
 * backend mapea a slugs `complaint.*`, design §10).
 */
export interface IComplaintSettings {
  manager_role_keys: string[]
  reporter_role_keys: string[]
  auto_notify_managers_on_create: boolean
  requires_evidence: boolean
  resolution_days_target: number | undefined
  post_to_reports_channel: boolean
}

// ── Forms ─────────────────────────────────────────────────────────────────────

/**
 * `evidence` is intentionally NOT part of the Zod schema — it is a file, handled
 * via local component state and appended to FormData at submit time (Package
 * intake precedent). `complaint.requires_evidence` (module-config) is enforced
 * server-side with a 422; the client never blocks submission on it.
 */
export const complaintFormSchema = z.object({
  title: z
    .string()
    .min(1, 'El título es requerido')
    .max(150, 'El título no puede superar 150 caracteres'),
  complaint_category_id: z.string().min(1, 'La categoría es requerida'),
  complaint_priority_id: z.string().min(1, 'La prioridad es requerida'),
  against_unit_id: z.string().optional(),
  against_description: z
    .string()
    .min(1, 'La descripción es requerida')
    .max(2000, 'La descripción no puede superar 2000 caracteres'),
})

export type ComplaintFormValues = z.infer<typeof complaintFormSchema>

export const complaintMessageSchema = z.object({
  body: z
    .string()
    .min(1, 'El mensaje es requerido')
    .max(2000, 'El mensaje no puede superar 2000 caracteres'),
})

export type ComplaintMessageFormValues = z.infer<typeof complaintMessageSchema>

export const complaintTransitionSchema = z.object({
  complaint_status_id: z.string().min(1, 'Selecciona un estado'),
})

export type ComplaintTransitionFormValues = z.infer<typeof complaintTransitionSchema>

export const complaintAssignSchema = z.object({
  assigned_user_id: z.string().min(1, 'Selecciona un responsable'),
})

export type ComplaintAssignFormValues = z.infer<typeof complaintAssignSchema>
