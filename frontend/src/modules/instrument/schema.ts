import type { IEntity } from '@/shared/interfaces/Entity'

/**
 * Read-only inspector for the EDI instrument catalog (design §10). No Zod
 * form schemas here: nothing in this module is editable — content enters
 * exclusively via `InstrumentSeeder` on the backend. `legacy_id` never
 * appears (hidden on every backend model, REQ-INS-004).
 */

export interface IInstrument extends IEntity {
  key: string
  name: string
}

export interface IInstrumentVersion extends IEntity {
  instrument_id: string
  key: string
  name: string
  age_calculation_mode: 'thirty_day_month' | 'calendar'
  is_default: boolean
  published_at: string | null
  content_hash: string | null
  source_note?: string | null
  instrument?: IInstrument
}

export interface IAgeGroup extends IEntity {
  instrument_version_id: string
  code: string
  ordinal: number
  is_alternate: boolean
  min_age_days: number | null
  max_age_days: number | null
  min_age_months: number | null
  max_age_months: number | null
  alternate_age_group_id: string | null
  materials_note?: string | null
  alternate?: IAgeGroup | null
}

export interface IResultColor extends IEntity {
  key: 'green' | 'yellow' | 'red'
  sort_order: number
}

export interface IDevelopmentArea extends IEntity {
  code: string
  scoring_kind: 'semaphore' | 'counter'
  is_development_axis: boolean
  sort_order: number
}

export interface IApplicationPosition extends IEntity {
  key: string
}

export interface IScreeningItemOption extends IEntity {
  screening_item_id: string
  label: string
  sort_order: number
  result_color_id: string
  result_color?: IResultColor
}

export interface IScreeningItem extends IEntity {
  instrument_version_id: string
  age_group_id: string
  development_area_id: string
  application_position_id: string
  code: string
  question: string
  sort_order: number
  options?: IScreeningItemOption[]
  development_area?: IDevelopmentArea
  application_position?: IApplicationPosition
}

export interface IAreaScoringRule extends IEntity {
  instrument_version_id: string
  age_group_id: string
  development_area_id: string
  item_count: number
  red_threshold: number
  green_threshold: number | null
  allows_yellow: boolean
  source: 'legacy_js' | 'legacy_js_commented' | 'extrapolated' | 'oracle_confirmed' | 'manual'
  notes?: string | null
  age_group?: IAgeGroup
  development_area?: IDevelopmentArea
}

export type IConditionOperator = 'all' | 'any' | 'not' | 'count' | 'sum' | 'color_in'

/** Closed grammar (design §5.4) — one operator key per node, recursively. */
export type ICondition =
  | { all: ICondition[] }
  | { any: ICondition[] }
  | { not: ICondition }
  | { count: { of: string; gte?: number; eq?: number; lte?: number } }
  | { sum: { of: string[]; gte: number } }
  | { color_in: { areas: string[]; is: 'red' | 'yellow' | 'green' } }

export interface IGlobalResultMessage extends IEntity {
  instrument_version_id: string
  key: 'red' | 'yellow_development' | 'yellow_social' | 'green'
  result_color_id: string
  headline: string
  referral_items: string[]
  sort_order: number
  result_color?: IResultColor
}

export interface IGlobalScoringRule extends IEntity {
  instrument_version_id: string
  age_group_id: string
  sequence: number
  condition: ICondition
  result_color_id: string
  global_result_message_id: string
  source: 'legacy_js' | 'oracle_confirmed' | 'manual'
  notes?: string | null
  age_group?: IAgeGroup
  result_color?: IResultColor
  message?: IGlobalResultMessage
}

export interface IRiskFactor extends IEntity {
  instrument_version_id: string
  code: string
  question: string
  sort_order: number
  auto_derivation_key: string | null
}

export interface ISex extends IEntity {
  key: 'male' | 'female'
}

export interface IHeadCircumferenceReference extends IEntity {
  instrument_version_id: string
  sex_id: string
  age_months: number
  min_circumference_mm: number
  max_circumference_mm: number
  sex?: ISex
}
