import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/**
 * Screening (Tamizaje EDI) — types + runtime schemas (REQ-SUI-011).
 *
 * Field names were verified against the live backend source (not a running
 * server — Modules/Screening/app/{Http/Controllers,Services,Models} and
 * Modules/Screening/app/Http/Requests), task 1.2. Every relation method on
 * every Eloquent model is snake_case and serializes 1:1 to these keys:
 * Screening (screening.php), ScreeningAnswer, ScreeningAreaResult,
 * ScreeningFinding, ScreeningScoreRun, ConfirmatoryTest, plus
 * ScreeningApplicationService::itemBankFor (item bank shape) and
 * ScreeningScoreService::assertAlternateAnswered /
 * ScreeningDomainException::alternateItemsRequired (422 payload shape).
 *
 * Inline relation objects only (no cross-module type imports) per house rule.
 */

// ── Central catalog rows (read-only, referenced inline) ────────────────────

export interface IResultColorRef extends IEntity {
  key: 'green' | 'yellow' | 'red'
  sort_order?: number
}

export interface IScreeningStatusRef extends IEntity {
  key: 'draft' | 'scored' | 'completed' | 'invalidated'
}

export interface IScreeningSituationRef extends IEntity {
  key: string
  sis_code: number
}

export interface IFindingTypeRef extends IEntity {
  key:
    | 'age_group_substituted_by_corrected_age'
    | 'unknown_perinatal_input'
    | 'auto_answer_overrode_manual'
    | 'unanswered_item'
    | 'missing_percentile_reference'
}

export interface IConfirmatoryTestResultRef extends IEntity {
  key: 'delay_confirmed' | 'delay_ruled_out' | 'inconclusive'
}

export interface IAgeGroupRef extends IEntity {
  code: string
  name?: string
  ordinal?: number
  min_age_days?: number | null
  max_age_days?: number | null
  min_age_months?: number | null
  max_age_months?: number | null
  materials_note?: string | null
}

/**
 * How the applied group was selected (S2): `age` = resolved from the child's age
 * (incl. corrected-age substitution); `chosen` = picked by the evaluator
 * ("Prueba EDI a elección").
 */
export type AgeGroupSelectionMode = 'age' | 'chosen'

/** One official group offered by GET /screening/age-groups. */
export interface IAgeGroupOption {
  id: string
  code: string
  name: string
  ordinal: number
  min_age_days: number | null
  max_age_days: number | null
  min_age_months: number | null
  max_age_months: number | null
  materials_note: string | null
}

// ── Item bank (POST /screening 201 body, GET /items) ───────────────────────

export interface ILockedReason {
  auto_derivation_key: string
  source_field: string
  value: number
}

export interface IBankOption {
  screening_item_option_id: string
  label: string
}

export interface IBankItem {
  screening_item_id: string
  code: string
  question: string
  is_alternate: boolean
  locked: boolean
  locked_reason: ILockedReason | null
  options: IBankOption[]
}

export interface IBankArea {
  development_area_id: string | null
  code: string | null
  name: string | null
  items: IBankItem[]
}

export interface IBankRiskFactor {
  risk_factor_id: string
  code: string
  question: string
  locked: boolean
  locked_reason: ILockedReason | null
}

export interface IScreeningItemBank {
  applied_age_group_code: string
  areas: IBankArea[]
  risk_factors: IBankRiskFactor[]
}

/**
 * An axis that needs the previous group's items right now ("ningún reactivo
 * logrado"). Computed by the server on every answer save and item-bank fetch;
 * `items` are all of that axis's alternate items, answered or not.
 */
export interface IAlternateRequirement {
  development_area_id: string | null
  code: string
  items: IBankItem[]
}

/** 422 ALTERNATE_ITEMS_REQUIRED — missing alternate items carry no lock/alternate flags. */
export interface IAlternateMissingItem {
  screening_item_id: string
  code: string
  question: string
  options: IBankOption[]
}

export interface IAlternateAreaRequirement {
  development_area_id: string | null
  code: string | null
  missing_items: IAlternateMissingItem[]
}

// ── Persisted entities ──────────────────────────────────────────────────────

export interface IScreeningAnswer extends IEntity {
  screening_id: string
  screening_item_id: string
  screening_item_option_id: string
  result_color_id: string
  is_auto_derived: boolean
  screening_item?: { id: string; code: string; question: string } | null
  screening_item_option?: { id: string; label: string } | null
  result_color?: IResultColorRef | null
}

export interface IScreeningAreaResult extends IEntity {
  screening_id: string
  development_area_id: string
  result_color_id: string
  red_count: number
  yellow_count: number
  green_count: number
  answered_own_count: number
  answered_alternate_count: number
  red_threshold: number | null
  green_threshold: number | null
  allows_yellow: boolean
  development_area?: { id: string; code: string; name: string } | null
  result_color?: IResultColorRef | null
}

export interface IScreeningRiskFactorAnswer extends IEntity {
  screening_id: string
  risk_factor_id: string
  is_present: boolean
  is_auto_derived: boolean
  risk_factor?: { id: string; code: string; question: string } | null
}

export interface IScreeningFinding extends IEntity {
  screening_id: string
  finding_type_id: string
  context: Record<string, unknown> | null
  finding_type?: IFindingTypeRef | null
}

export interface IScreeningScoreRun extends IEntity {
  screening_id: string
  instrument_version_id: string
  is_authoritative: boolean
  trigger: 'initial' | 'rescore' | 'simulation'
  reason: string | null
  engine_version: string
  rules_fingerprint: string
  result_color_id: string | null
  instrument_version?: { id: string; key: string; name?: string } | null
  result_color?: IResultColorRef | null
}

export interface IConfirmatoryTest extends IEntity {
  screening_id: string
  child_id: string
  test_name: string
  applied_at: string | null
  confirmatory_test_result_id: string | null
  notes: string | null
  confirmatory_test_result?: IConfirmatoryTestResultRef | null
}

export interface IScreening extends IEntity {
  visit_id: string
  child_id: string
  instrument_version_id: string
  age_group_id: string
  applied_age_group_id: string
  /** Absent on payloads built before S2; treat a missing value as `age`. */
  age_group_selection_mode?: AgeGroupSelectionMode
  chronological_age_days: number
  corrected_age_days: number | null
  weight_grams: number | null
  height_mm: number | null
  head_circumference_mm: number | null
  screening_status_id: string
  result_color_id: string | null
  global_result_message_id: string | null
  situation_id: string | null
  alert_sign_count: number | null
  risk_factor_count: number | null
  engine_version: string | null
  rules_fingerprint: string | null
  scored_at: string | null
  completed_at: string | null
  previous_screening_id: string | null
  previous_result_color_id: string | null
  invalidation_reason?: string | null
  notes: string | null

  visit?: {
    id: string
    receipt_number: string
    visit_date: string
    physical_receipt_number?: string | null
    health_center?: { id: string; name: string } | null
    practitioner?: { id: string; user?: { id: string; name?: string } | null } | null
  } | null
  child?: {
    id: string
    first_name: string
    last_name_paternal: string
    last_name_maternal?: string | null
    // curp is optional: present when the nominal listing (edi-reports-and-indicators)
    // requests the `child` relation, not sent by every screening consumer.
    curp?: string | null
    sex?: { id: string; name: string } | null
  } | null
  instrument_version?: { id: string; key: string; name?: string } | null
  age_group?: IAgeGroupRef | null
  applied_age_group?: IAgeGroupRef | null
  /**
   * Group the age rules would apply (corrected age included). Only differs from
   * `applied_age_group` in "Prueba EDI a elección"; null for older screenings.
   */
  age_derived_age_group?: IAgeGroupRef | null
  screening_status?: IScreeningStatusRef | null
  result_color?: IResultColorRef | null
  global_result_message?: {
    id: string
    key: string
    headline: string
    referral_items: string[]
  } | null
  situation?: IScreeningSituationRef | null
  previous_screening?: { id: string } | null
  previous_result_color?: IResultColorRef | null

  answers?: IScreeningAnswer[]
  area_results?: IScreeningAreaResult[]
  risk_factor_answers?: IScreeningRiskFactorAnswer[]
  findings?: IScreeningFinding[]
  score_runs?: IScreeningScoreRun[]
  confirmatory_test?: IConfirmatoryTest | null
}

// ── Typed API error envelope (REQ-SUI-011) ──────────────────────────────────

export type IScreeningApiError =
  | { code: 'SCREENING_NOT_APPLICABLE'; message: string }
  | { code: 'VISIT_ALREADY_SCREENED'; message: string }
  | { code: 'INCOMPLETE_ANSWERS'; message: string; missing_item_ids: string[] }
  | { code: 'ALTERNATE_ITEMS_REQUIRED'; message: string; areas: IAlternateAreaRequirement[] }
  | { code: 'SCREENING_FROZEN'; message: string }
  | { code: 'INVALID_STATUS_TRANSITION'; message: string }
  | { code: 'VERSION_PINNED'; message: string }
  | { code: 'RULES_FINGERPRINT_MISMATCH'; message: string }
  | { code: 'CONFIRMATORY_NOT_APPLICABLE'; message: string }

// ── Static Zod schemas (somatometry/notes + confirmatory test form) ────────

/** Ranges mirror ScreeningCreateRequest/ScreeningUpdateRequest (design D12). */
export const screeningSomatometrySchema = z.object({
  weight_grams: z
    .number()
    .int()
    .min(1500, 'El peso está fuera del rango plausible.')
    .max(40000, 'El peso está fuera del rango plausible.')
    .nullable()
    .optional(),
  height_mm: z
    .number()
    .int()
    .min(350, 'La talla está fuera del rango plausible.')
    .max(1400, 'La talla está fuera del rango plausible.')
    .nullable()
    .optional(),
  head_circumference_mm: z
    .number()
    .int()
    .min(250, 'El perímetro cefálico está fuera del rango plausible.')
    .max(700, 'El perímetro cefálico está fuera del rango plausible.')
    .nullable()
    .optional(),
  notes: z.string().optional(),
})

export type ScreeningSomatometryFormValues = z.infer<typeof screeningSomatometrySchema>

export const confirmatoryTestFormSchema = z.object({
  screening_id: z.string().min(1),
  test_name: z.string().min(1, 'El nombre de la prueba es requerido'),
  applied_at: z.string().optional(),
  confirmatory_test_result_id: z.string().optional(),
  notes: z.string().optional(),
})

export type ConfirmatoryTestFormValues = z.infer<typeof confirmatoryTestFormSchema>

export const rescoreFormSchema = z.object({
  reason: z.string().min(1, 'El motivo es requerido'),
  authoritative: z.boolean(),
  instrument_version_key: z.string().optional(),
})

export type RescoreFormValues = z.infer<typeof rescoreFormSchema>

export const invalidateFormSchema = z.object({
  reason: z.string().min(1, 'El motivo es requerido'),
})

export type InvalidateFormValues = z.infer<typeof invalidateFormSchema>
