import type { IEntity } from '@/shared/interfaces/Entity'

/**
 * Reports (edi-reports-and-indicators) — indicator dashboard payload types.
 *
 * The nominal listing reuses `IScreening` from `@/modules/screening/schema`
 * directly (same entity shape as `/api/screening`) — no duplicate interface
 * here, per house rule against redefining an existing schema.
 */

export interface IIndicatorSituationBreakdown {
  [situationKey: string]: number
}

export interface IIndicatorAreaColorCounts {
  green: number
  yellow: number
  red: number
}

export interface IIndicatorAreaBreakdown {
  [areaCode: string]: IIndicatorAreaColorCounts
}

/** GET /api/reports/indicators/summary */
export interface IIndicatorSummary {
  period: string
  health_center_id: string | null
  screenings_applied: number
  screenings_completed: number
  children_screened: number
  result_green_count: number
  result_yellow_count: number
  result_red_count: number
  percent_green: number
  percent_yellow: number
  percent_red: number
  first_time_count: number
  alert_sign_screening_count: number
  risk_factor_screening_count: number
  confirmatory_pending_count: number
  situation_breakdown: IIndicatorSituationBreakdown
}

/** One entry of GET /api/reports/indicators/monthly-series */
export interface IIndicatorMonthlyPoint {
  period: string
  screenings_applied: number
  result_green_count: number
  result_yellow_count: number
  result_red_count: number
}

/** GET /api/reports/indicators/area-breakdown */
export interface IIndicatorAreaBreakdownResponse {
  period: string
  health_center_id: string | null
  areas: IIndicatorAreaBreakdown
}

/** One row of GET /api/reports/indicators/concentrate */
export interface IIndicatorConcentrateRow {
  period: string
  health_center_id: string | null
  screenings_applied: number
  screenings_completed: number
  children_screened: number
  result_green_count: number
  result_yellow_count: number
  result_red_count: number
  first_time_count: number
  alert_sign_screening_count: number
  risk_factor_screening_count: number
  confirmatory_pending_count: number
  area_breakdown: IIndicatorAreaBreakdown
  situation_breakdown: IIndicatorSituationBreakdown
}

export interface IIndicatorFilters {
  period?: string
  from?: string
  to?: string
  months?: number
  health_center_id?: string
}

// ─── Concentrado EDI / Reporte general (S4/S5) ───────────────────────────────

/**
 * One column of the "área o eje de impacto" block. The list comes from the
 * server (`meta.impact_axes`, read from the `development_area` catalog plus
 * FRB), never from a list in code.
 */
export interface IImpactAxis {
  code: string
  name: string
}

function isImpactAxis(value: unknown): value is IImpactAxis {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as { code?: unknown }).code === 'string' &&
    typeof (value as { name?: unknown }).name === 'string'
  )
}

/** Reads the axis columns the Concentrado EDI endpoint sends in its `meta`. */
export function impactAxesFromMeta(meta: object | null | undefined): IImpactAxis[] {
  if (!meta || !('impact_axes' in meta)) return []
  const axes: unknown = meta.impact_axes
  return Array.isArray(axes) ? axes.filter(isImpactAxis) : []
}

export interface IReportStatusRef {
  key: string
  name: string
}

/** One counted Prueba EDI (latest or previous) of a report row. */
export interface IChildReportScreening {
  id: string
  /** Visit date, civil `YYYY-MM-DD`. */
  date: string
  result: { key: 'green' | 'yellow' | 'red'; name: string }
  /** Keyed by axis code (see `impactAxesFromMeta`). */
  impacts: Record<string, boolean>
  corrected_age_label: string | null
}

/** Latest nutritional assessment ("última medición"). */
export interface IChildReportNutrition {
  measured_on: string
  weight_grams: number | null
  height_mm: number | null
  weight_for_height_status: IReportStatusRef | null
  weight_for_age_status: IReportStatusRef | null
  height_for_age_status: IReportStatusRef | null
}

/** One row of GET /api/reports/general (id = child id). */
export interface IGeneralReportRow extends IEntity {
  first_name: string
  last_name_paternal: string
  last_name_maternal: string | null
  child_name: string
  curp: string | null
  external_id: string | null
  birth_date: string
  age_label: string
  age_months: number
  health_center_name: string | null
  caregiver_name: string | null
  caregiver_phone: string | null
  nutrition: IChildReportNutrition | null
  last_screening: IChildReportScreening | null
  previous_screening: IChildReportScreening | null
}

/** One row of GET /api/reports/edi-concentrate (id = child id). */
export interface IEdiConcentrateRow extends IGeneralReportRow {
  gestational_age_weeks: number | null
  evaluator_name: string | null
  follow_up_summary: string | null
  /** Next evaluation month, `YYYY-MM`. */
  next_evaluation: string | null
}

/** Query string accepted by both per-child report endpoints and their exports. */
export interface IChildReportQuery {
  page?: number
  limit?: number
  search?: string
  from?: string
  to?: string
  health_center_ids?: string[]
}
