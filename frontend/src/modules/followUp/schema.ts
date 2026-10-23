import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/**
 * FollowUp / Referral — types + runtime schemas (REQ-FUP-009).
 *
 * Field names verified against the live backend source: Modules/FollowUp/app/
 * {Models,Http/Controllers,Http/Requests,Services}. Every relation method on
 * every Eloquent model is snake_case and serializes 1:1 to these keys.
 *
 * Inline relation objects only (no cross-module type imports) per house rule.
 */

// ── Central catalog rows (read-only, referenced inline) ────────────────────

export interface IFollowUpTypeRef extends IEntity {
  key: 'reassess_3_months' | 'specialist_referral' | 'confirmatory_test'
  sort_order?: number
}

export interface IFollowUpStatusRef extends IEntity {
  key: 'pending' | 'in_progress' | 'completed' | 'overdue' | 'cancelled'
  sort_order?: number
}

// ── Persisted entities ──────────────────────────────────────────────────────

export interface IReferral extends IEntity {
  follow_up_id: string
  referral_specialty_id: string
  referred_at: string
  institution?: string | null
  outcome_notes?: string | null
  referral_specialty?: { id: string; name: string } | null
}

export interface IFollowUp extends IEntity {
  child_id: string
  screening_id: string
  follow_up_type_id: string
  follow_up_status_id: string
  due_date: string
  referral_specialty_id?: string | null
  closed_by_screening_id?: string | null
  completed_at?: string | null
  cancellation_reason?: string | null
  notes?: string | null

  child?: {
    id: string
    first_name: string
    last_name_paternal: string
    last_name_maternal?: string | null
  } | null
  screening?: { id: string; result_color_id?: string | null } | null
  follow_up_type?: IFollowUpTypeRef | null
  follow_up_status?: IFollowUpStatusRef | null
  referral_specialty?: { id: string; name: string } | null
  closed_by_screening?: { id: string } | null
  referrals?: IReferral[]
}

// ── Typed API error envelope ─────────────────────────────────────────────

export type IFollowUpApiError =
  | { code: 'FOLLOW_UP_DUPLICATE'; message: string }
  | { code: 'FOLLOW_UP_FROZEN'; message: string }
  | { code: 'INVALID_STATUS_TRANSITION'; message: string }
  | { code: 'FOLLOW_UP_TYPE_MISMATCH'; message: string }
  | { code: 'SCREENING_NOT_ELIGIBLE'; message: string }

// ── Static Zod schemas ──────────────────────────────────────────────────

export const followUpFormSchema = z.object({
  screening_id: z.string().min(1, 'La Prueba EDI es requerida'),
  follow_up_type_id: z.string().min(1, 'El tipo de seguimiento es requerido'),
  due_date: z.string().min(1, 'La fecha límite es requerida'),
  referral_specialty_id: z.string().optional(),
  notes: z.string().optional(),
})

export type FollowUpFormValues = z.infer<typeof followUpFormSchema>

export const followUpUpdateFormSchema = z.object({
  due_date: z.string().min(1, 'La fecha límite es requerida'),
  referral_specialty_id: z.string().optional(),
  notes: z.string().optional(),
})

export type FollowUpUpdateFormValues = z.infer<typeof followUpUpdateFormSchema>

export const followUpCancelFormSchema = z.object({
  reason: z.string().min(1, 'El motivo es requerido'),
})

export type FollowUpCancelFormValues = z.infer<typeof followUpCancelFormSchema>

export const referralFormSchema = z.object({
  follow_up_id: z.string().min(1),
  referral_specialty_id: z.string().min(1, 'La especialidad es requerida'),
  referred_at: z.string().min(1, 'La fecha de referencia es requerida'),
  institution: z.string().optional(),
  outcome_notes: z.string().optional(),
})

export type ReferralFormValues = z.infer<typeof referralFormSchema>

/** Construye el nombre completo del niño, mostrando solo lo que existe. */
export function followUpChildName(child?: IFollowUp['child']): string {
  if (!child) return '—'

  return [child.first_name, child.last_name_paternal, child.last_name_maternal].filter(Boolean).join(' ')
}
