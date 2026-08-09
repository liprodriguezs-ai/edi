import {
  AMOUNT_MODE,
  SURCHARGE_MODE,
  type ChargePolicyFormValues,
  type IChargePolicy,
  type IInitialBatch,
  type ISurchargeRule,
  type SurchargeRuleFormValues,
  type TargetType,
} from '@/modules/chargePolicy/schema'
import { minorUnitsToPesos, moneyToMinorUnits } from '@/shared/utils/money'

/** basis points → porcentaje visible (500 → 5). */
function basisPointsToPercent(basisPoints: number): number {
  return basisPoints / 100
}

/**
 * Hidrata las reglas de recargo del form a partir de las que devuelve el backend.
 * Convierte el valor crudo a unidad visible según el modo: PERCENT → %, FIXED → pesos.
 * El tope (cap_amount) siempre está en minor units → pesos.
 */
function surchargeRulesFromPolicy(policy: IChargePolicy): SurchargeRuleFormValues[] {
  const rules: ISurchargeRule[] = policy.surcharge_rules ?? []
  return rules.map((rule) => {
    const isPercent = rule.surcharge_mode_id === SURCHARGE_MODE.PERCENT
    return {
      id: rule.id,
      from_month: rule.from_month,
      surcharge_mode_id: rule.surcharge_mode_id,
      surcharge_value: isPercent
        ? basisPointsToPercent(rule.surcharge_value)
        : minorUnitsToPesos(rule.surcharge_value),
      recurrence: rule.recurrence,
      cap_amount: rule.cap_amount != null ? minorUnitsToPesos(rule.cap_amount) : null,
    }
  })
}

function toPesosOrNull(money: IChargePolicy['fixed_amount']): number | null {
  const minor = moneyToMinorUnits(money ?? null)
  return minor > 0 ? minorUnitsToPesos(minor) : null
}

/** Fecha de hoy en formato YYYY-MM-DD sin acoplarse a la zona horaria UTC. */
function todayIso(now: Date): string {
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Campos de alcance/fechas del form derivados del lote inicial (reverso de buildPolicyTargetSelector). */
interface BatchScopeDefaults {
  target_type: TargetType
  target_unit_type_ids: string[]
  target_tower_ids: string[]
  target_section_ids: string[]
  target_floor: string
  target_unit_ids: string[]
  target_feature_ids: string[]
  target_unit_id: string | undefined
  issued_at: string
  due_date: string
}

/** Alcance/fechas "vacíos" (sin lote inicial o en alta). */
function emptyBatchScope(today: string): BatchScopeDefaults {
  return {
    target_type: 'all',
    target_unit_type_ids: [],
    target_tower_ids: [],
    target_section_ids: [],
    target_floor: '',
    target_unit_ids: [],
    target_feature_ids: [],
    target_unit_id: undefined,
    issued_at: today,
    due_date: '',
  }
}

/** Lee un string del selector del lote o '' si no aplica. */
function selectorString(selector: Record<string, unknown> | null | undefined, key: string): string {
  const value = selector?.[key]
  return typeof value === 'string' ? value : ''
}

/**
 * Lee el primer string de una key de array del selector (forma del TargetResolver:
 * floors), con fallback a string legacy. Usado para `floors` (campo single).
 */
function selectorFirst(selector: Record<string, unknown> | null | undefined, key: string): string {
  const value = selector?.[key]
  if (Array.isArray(value)) {
    return value.find((item): item is string => typeof item === 'string') ?? ''
  }
  return typeof value === 'string' ? value : ''
}

/**
 * Lee TODOS los strings de una key de array del selector (forma del TargetResolver:
 * unit_type_ids/tower_ids/unit_feature_ids), con fallback legacy string → [id].
 * Hidrata los MULTISELECT de tipo/torre/característica.
 */
function selectorIds(selector: Record<string, unknown> | null | undefined, key: string): string[] {
  const value = selector?.[key]
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string')
  }
  return typeof value === 'string' && value ? [value] : []
}

/**
 * Hidrata el alcance/fechas del form a partir del lote correcto según el modo:
 *  - periódica: el template `is_initial = true` (el cron lo clona por período).
 *  - eventual/directa: el único lote `is_initial = false` (no hay template a clonar).
 * Reverso de buildPolicyTargetSelector: el selector se mapea de vuelta a los campos.
 * Mientras `has_billed = false` este alcance sigue siendo editable (buildPolicyUpdatePayload
 * lo reenvía en el PATCH); una vez facturada, el form lo muestra deshabilitado como contexto.
 */
function batchScopeFromPolicy(policy: IChargePolicy): BatchScopeDefaults {
  const batches = policy.charge_batches ?? []
  const initial: IInitialBatch | undefined =
    batches.find((batch) => batch.is_initial === true) ??
    batches.find((batch) => batch.is_initial === false)
  if (!initial) {
    return emptyBatchScope('')
  }

  const selector = initial.target_selector ?? null
  const unitIds = selector?.unit_ids
  return {
    target_type: initial.target_type ?? 'all',
    target_unit_type_ids: selectorIds(selector, 'unit_type_ids'),
    target_tower_ids: selectorIds(selector, 'tower_ids'),
    target_section_ids: selectorIds(selector, 'section_ids'),
    target_floor: selectorFirst(selector, 'floors'),
    target_unit_ids: Array.isArray(unitIds)
      ? unitIds.filter((id): id is string => typeof id === 'string')
      : [],
    target_feature_ids: selectorIds(selector, 'unit_feature_ids'),
    target_unit_id: selectorString(selector, 'unit_id') || undefined,
    issued_at: initial.issued_at ? initial.issued_at.slice(0, 10) : '',
    due_date: initial.due_date ? initial.due_date.slice(0, 10) : '',
  }
}

/**
 * Valores por defecto del form de política. Pura — testeable con fecha fija.
 * En alta requiere el `chargeConceptId` (la política pertenece a un concepto).
 */
export function buildPolicyDefaults(
  chargeConceptId: string,
  policy: IChargePolicy | null,
  now: Date
): ChargePolicyFormValues {
  const today = todayIso(now)

  if (!policy) {
    return {
      charge_concept_id: chargeConceptId,
      name: '',
      amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED,
      fixed_amount: null,
      min_amount: null,
      ideal_amount: null,
      max_amount: null,
      prorate_total_amount: null,
      grace_days: null,
      due_soon_days: null,
      periodicity_id: '',
      valid_from: today,
      valid_to: '',
      is_active: true,
      target_type: 'all',
      target_unit_type_ids: [],
      target_tower_ids: [],
      target_section_ids: [],
      target_floor: '',
      target_unit_ids: [],
      target_feature_ids: [],
      target_unit_id: undefined,
      issued_at: today,
      due_date: '',
      surcharge_rules: [],
    }
  }

  // El alcance/fechas se hidratan del lote correcto (template o único lote directo)
  // para que el form unificado muestre a quiénes y cuándo se cobró. Editable mientras
  // `has_billed = false` (buildPolicyUpdatePayload lo reenvía en el PATCH); tras
  // facturar, el form lo deshabilita y queda solo como contexto visible.
  const scope = batchScopeFromPolicy(policy)

  return {
    charge_concept_id: policy.charge_concept_id,
    name: policy.name,
    amount_mode_id: policy.amount_mode_id ?? AMOUNT_MODE.FIXED_REQUIRED,
    fixed_amount: toPesosOrNull(policy.fixed_amount),
    min_amount: toPesosOrNull(policy.min_amount),
    ideal_amount: toPesosOrNull(policy.ideal_amount),
    max_amount: toPesosOrNull(policy.max_amount),
    prorate_total_amount: toPesosOrNull(policy.prorate_total_amount),
    grace_days: policy.grace_days ?? null,
    due_soon_days: policy.due_soon_days ?? null,
    periodicity_id: policy.periodicity_id ?? '',
    valid_from: policy.valid_from ? policy.valid_from.slice(0, 10) : '',
    valid_to: policy.valid_to ? policy.valid_to.slice(0, 10) : '',
    is_active: policy.is_active ?? true,
    target_type: scope.target_type,
    target_unit_type_ids: scope.target_unit_type_ids,
    target_tower_ids: scope.target_tower_ids,
    target_section_ids: scope.target_section_ids,
    target_floor: scope.target_floor,
    target_unit_ids: scope.target_unit_ids,
    target_feature_ids: scope.target_feature_ids,
    target_unit_id: scope.target_unit_id,
    issued_at: scope.issued_at,
    due_date: scope.due_date,
    surcharge_rules: surchargeRulesFromPolicy(policy),
  }
}
