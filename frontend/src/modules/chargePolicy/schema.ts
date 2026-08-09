import { AMOUNT_MODE, PERIODICITY, type IChargeConcept } from '@/modules/chargeConcept/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { buildTargetSelector as buildResolverSelector } from '@/shared/utils/targetSelector'
import { z } from 'zod'

// AMOUNT_MODE / PERIODICITY son catálogos CENTRALES (UUIDs fijos). Su fuente única
// es chargeConcept/schema; los reexportamos para que el módulo de políticas (que es
// el dueño de la lógica de monto/periodicidad) tenga un único punto de importación.
export { AMOUNT_MODE, PERIODICITY }

/** Tipos de alcance del cobro directo — espejo de TargetResolver::TARGET_TYPES. */
export const TARGET_TYPES = [
  'all',
  'by_type',
  'by_tower',
  'by_section',
  'by_floor',
  'manual',
  'by_feature',
  'with_vehicles',
  'by_share',
  'single_unit',
] as const

export type TargetType = (typeof TARGET_TYPES)[number]

/**
 * Modos de recargo moratorio — espejo del catálogo CENTRAL `surcharge_mode`
 * (UUIDs fijos). El código ramifica sobre estos UUIDs para interpretar
 * `surcharge_value`:
 *  - PERCENT: basis points (500 = 5.00%) sobre la base morosa.
 *  - FIXED: monto plano en minor units (centavos).
 */
export const SURCHARGE_MODE = {
  PERCENT: 'c595cf11-8209-4e34-90e5-b7957478ee39',
  FIXED: '6bc44bb8-cde0-4ce8-8c6f-5d10ddf9aa4c',
} as const

/** Recurrencia del recargo — espejo de ChargeSurchargeRule::RECURRENCES. */
export const SURCHARGE_RECURRENCES = ['one_time', 'per_month_overdue'] as const

export type SurchargeRecurrence = (typeof SURCHARGE_RECURRENCES)[number]

interface INamedCatalog {
  id: string
  name: string
  /** Present on periodicity rows; true = cron auto-generates batches for this periodicity. */
  is_automatic?: boolean
}

/**
 * Regla de recargo moratorio tal como la DEVUELVE el backend (include `surcharge_rules`).
 * `surcharge_value` y `cap_amount` son enteros CRUDOS (no MoneyIntegerCast):
 *  - `surcharge_value` = basis points en modo PERCENT, minor units en modo FIXED.
 *  - `cap_amount` = tope en minor units (o null).
 */
export interface ISurchargeRule {
  id?: string
  from_month: number
  surcharge_mode_id: string
  surcharge_value: number
  recurrence: SurchargeRecurrence
  cap_amount?: number | null
  is_active?: boolean
  surcharge_mode?: INamedCatalog | null
}

export interface IChargePolicy extends IEntity {
  charge_concept_id: string
  name: string
  amount_mode_id: string
  fixed_amount?: IMoney | number | null
  min_amount?: IMoney | number | null
  ideal_amount?: IMoney | number | null
  max_amount?: IMoney | number | null
  prorate_total_amount?: IMoney | number | null
  grace_days?: number | null
  /**
   * Override por regla de los días "por vencer". Cuando es null, se usa el valor
   * del clúster (collections.due_soon_days). Columna nullable de charge_policy.
   */
  due_soon_days?: number | null
  periodicity_id: string
  valid_from?: string | null
  valid_to?: string | null
  is_active?: boolean
  /**
   * true cuando la regla ya generó al menos un `charge` (`withExists('charges as
   * has_billed')` en el backend, sin N+1). Gate del front para bloquear alcance
   * (`target_type`/`target_selector`) y `periodicity_id` — espeja el `prohibitedIf`
   * del backend. Vigencia (`valid_from`/`valid_to`) y monto siguen editables siempre.
   */
  has_billed?: boolean
  concept?: IChargeConcept | null
  amount_mode?: INamedCatalog | null
  periodicity?: INamedCatalog | null
  /** Calendario de recargos moratorios (1:N). Llega del include `surcharge_rules`. */
  surcharge_rules?: ISurchargeRule[] | null
  /**
   * Lotes generados por la regla. Llega del include `charge_batches` (la relación
   * en el modelo ya está en snake_case, así que la key del include y la key JSON
   * coinciden). El lote con `is_initial === true` es la plantilla cuyo alcance/fechas
   * hidrata el form en edición.
   */
  charge_batches?: IInitialBatch[] | null
}

/** Subconjunto del lote necesario para hidratar el alcance/fechas del form en edición. */
export interface IInitialBatch {
  id: string
  is_initial?: boolean
  target_type?: TargetType
  target_selector?: Record<string, unknown> | null
  issued_at?: string | null
  due_date?: string | null
  month?: number | null
}

/**
 * Una regla de recargo moratorio EN EL FORM (unidades visibles):
 *  - `surcharge_value` en % (modo PERCENT) o en pesos (modo FIXED) — se convierte
 *    a basis points / minor units en buildPolicyPayload según el modo.
 *  - `cap_amount` en pesos (tope opcional) — se convierte a minor units al enviar.
 * `from_month` y `surcharge_value` son nullable para tolerar el input vacío (igual
 * que los montos); el superRefine los exige cuando la regla existe.
 */
export const surchargeRuleFormSchema = z
  .object({
    id: z.string().optional(),
    from_month: z.number().int().min(1, 'Mínimo mes 1').max(120, 'Máximo mes 120').nullable(),
    surcharge_mode_id: z.string().min(1, 'Elige el tipo de recargo'),
    surcharge_value: z.number().positive('Debe ser mayor a 0').nullable(),
    recurrence: z.enum(SURCHARGE_RECURRENCES),
    cap_amount: z.number().positive('Debe ser mayor a 0').nullable(),
  })
  .superRefine((rule, ctx) => {
    if (rule.from_month == null) {
      ctx.addIssue({ code: 'custom', path: ['from_month'], message: 'Indica el mes de morosidad' })
    }
    if (rule.surcharge_value == null) {
      ctx.addIssue({
        code: 'custom',
        path: ['surcharge_value'],
        message: 'Indica el valor del recargo',
      })
    }
  })

export type SurchargeRuleFormValues = z.infer<typeof surchargeRuleFormSchema>

/** Montos del form en PESOS; se convierten a minor units al enviar. */
export const chargePolicyFormSchema = z
  .object({
    charge_concept_id: z.string().min(1, 'El concepto es requerido'),
    name: z.string().min(1, 'El nombre es requerido').max(255),
    amount_mode_id: z.string().min(1, 'El modo de monto es requerido'),
    fixed_amount: z.number().min(0).nullable(),
    min_amount: z.number().min(0).nullable(),
    ideal_amount: z.number().min(0).nullable(),
    max_amount: z.number().min(0).nullable(),
    prorate_total_amount: z.number().min(0).nullable(),
    grace_days: z.number().int().min(0).max(365).nullable(),
    // Override opcional por regla de los días "por vencer"; vacío → usa el del clúster.
    due_soon_days: z.number().int().min(0).max(60).nullable().optional(),
    periodicity_id: z.string().min(1, 'La periodicidad es requerida'),
    // recurrente: requeridas; eventual: se ignoran (no se capturan).
    valid_from: z.string().optional(),
    valid_to: z.string().optional(),
    is_active: z.boolean(),
    // eventual: parámetros del lote de un solo tiro.
    // tipo/torre/sección/característica son MULTISELECT (arrays). `target_floor` y
    // `target_unit_id` siguen vivos para datos legacy (by_floor / single_unit).
    // `with_vehicles` no lleva campo propio: sin selector, igual que `all`.
    target_type: z.enum(TARGET_TYPES),
    target_unit_type_ids: z.array(z.string()).optional(),
    target_tower_ids: z.array(z.string()).optional(),
    target_section_ids: z.array(z.string()).optional(),
    target_floor: z.string().optional(),
    target_unit_ids: z.array(z.string()).optional(),
    target_feature_ids: z.array(z.string()).optional(),
    target_unit_id: z.string().optional(),
    issued_at: z.string().optional(),
    due_date: z.string().optional(),
    // Calendario de recargos moratorios (opcional). Cada regla escala desde un mes de mora.
    surcharge_rules: z.array(surchargeRuleFormSchema).optional(),
  })
  .superRefine((values, ctx) => {
    const fixedModes: string[] = [AMOUNT_MODE.FIXED_REQUIRED, AMOUNT_MODE.FIXED_OPTIONAL]
    if (
      fixedModes.includes(values.amount_mode_id) &&
      (values.fixed_amount == null || values.fixed_amount <= 0)
    ) {
      ctx.addIssue({
        code: 'custom',
        path: ['fixed_amount'],
        message: 'El monto fijo es requerido',
      })
    }
    if (values.amount_mode_id === AMOUNT_MODE.RANGE) {
      if (values.min_amount == null)
        ctx.addIssue({ code: 'custom', path: ['min_amount'], message: 'Requerido' })
      if (values.ideal_amount == null)
        ctx.addIssue({ code: 'custom', path: ['ideal_amount'], message: 'Requerido' })
      if (values.max_amount == null)
        ctx.addIssue({ code: 'custom', path: ['max_amount'], message: 'Requerido' })
      // Orden coherente del rango: min ≤ ideal ≤ max (solo cuando los tres están presentes).
      if (
        values.min_amount != null &&
        values.ideal_amount != null &&
        values.min_amount > values.ideal_amount
      ) {
        ctx.addIssue({
          code: 'custom',
          path: ['min_amount'],
          message: 'El mínimo no puede ser mayor al ideal',
        })
      }
      if (
        values.ideal_amount != null &&
        values.max_amount != null &&
        values.ideal_amount > values.max_amount
      ) {
        ctx.addIssue({
          code: 'custom',
          path: ['ideal_amount'],
          message: 'El ideal no puede ser mayor al máximo',
        })
      }
    }
    if (
      values.amount_mode_id === AMOUNT_MODE.BY_SHARE &&
      (values.prorate_total_amount == null || values.prorate_total_amount <= 0)
    ) {
      ctx.addIssue({
        code: 'custom',
        path: ['prorate_total_amount'],
        message: 'El monto total a prorratear es requerido',
      })
    }

    // Las fechas se validan según la PERIODICIDAD:
    //  - recurrente (cualquier periodicidad ≠ eventual) → vigencia valid_from/valid_to REQUERIDA.
    //  - eventual → fechas del cobro issued_at/due_date REQUERIDAS.
    const isEventual = values.periodicity_id === PERIODICITY.EVENTUAL
    if (!isEventual) {
      // Recurrente: ventana de vigencia REQUERIDA (el cron usa valid_from/valid_to).
      if (!values.valid_from) {
        ctx.addIssue({
          code: 'custom',
          path: ['valid_from'],
          message: 'La vigencia inicial es requerida',
        })
      }
      if (!values.valid_to) {
        ctx.addIssue({
          code: 'custom',
          path: ['valid_to'],
          message: 'La vigencia final es requerida',
        })
      }
      if (values.valid_to && values.valid_from && values.valid_to < values.valid_from) {
        ctx.addIssue({
          code: 'custom',
          path: ['valid_to'],
          message: "La fecha 'Hasta' debe ser posterior o igual a 'Vigente desde'.",
        })
      }
    } else {
      // Eventual: las fechas del cobro son REQUERIDAS.
      if (!values.issued_at) {
        ctx.addIssue({
          code: 'custom',
          path: ['issued_at'],
          message: 'La fecha de generación es requerida',
        })
      }
      if (!values.due_date) {
        ctx.addIssue({
          code: 'custom',
          path: ['due_date'],
          message: 'La fecha de vencimiento es requerida',
        })
      }
      if (values.due_date && values.issued_at && values.due_date < values.issued_at) {
        ctx.addIssue({
          code: 'custom',
          path: ['due_date'],
          message: 'Debe ser posterior o igual a la generación',
        })
      }
    }

    // El alcance (target_type + su selector) aplica a AMBOS modos: el lote
    // inicial — periódico o directo — se genera con este alcance (espeja el contrato
    // backend: target_type es requerido en ambos modos).
    // 'all', 'by_share' y 'with_vehicles' NO requieren selector: alcanzan a todas las
    // unidades activas (o con vehículo) sin selector propio. Por eso no tienen case aquí.
    const needTarget = (cond: boolean, path: string, message: string) => {
      if (cond) ctx.addIssue({ code: 'custom', path: [path], message })
    }
    switch (values.target_type) {
      case 'by_type':
        needTarget(
          !values.target_unit_type_ids?.length,
          'target_unit_type_ids',
          'Selecciona al menos un tipo de unidad'
        )
        break
      case 'by_tower':
        needTarget(
          !values.target_tower_ids?.length,
          'target_tower_ids',
          'Selecciona al menos una torre'
        )
        break
      case 'by_section':
        needTarget(
          !values.target_section_ids?.length,
          'target_section_ids',
          'Selecciona al menos una sección'
        )
        break
      case 'by_floor':
        needTarget(!values.target_floor?.trim(), 'target_floor', 'Indica el piso')
        break
      case 'manual':
        needTarget(
          !values.target_unit_ids?.length,
          'target_unit_ids',
          'Selecciona al menos una unidad'
        )
        break
      case 'by_feature':
        needTarget(
          !values.target_feature_ids?.length,
          'target_feature_ids',
          'Selecciona al menos una característica'
        )
        break
      case 'single_unit':
        needTarget(!values.target_unit_id, 'target_unit_id', 'Selecciona la unidad')
        break
    }

    // El mes de inicio de cada recargo debe ser ÚNICO (un solo recargo por mes de
    // mora). Marca inline cada fila que repite un mes ya usado para feedback directo.
    const surchargeRules = values.surcharge_rules ?? []
    const seenSurchargeMonths = new Set<number>()
    for (let index = 0; index < surchargeRules.length; index++) {
      const month = surchargeRules[index].from_month
      if (month == null) continue
      if (seenSurchargeMonths.has(month)) {
        ctx.addIssue({
          code: 'custom',
          path: ['surcharge_rules', index, 'from_month'],
          message: 'No repitas el mes de morosidad',
        })
        continue
      }
      seenSurchargeMonths.add(month)
    }
  })

export type ChargePolicyFormValues = z.infer<typeof chargePolicyFormSchema>

/** Subconjunto de campos del form que determinan el alcance del lote. */
export type PolicyTargetFields = Pick<
  ChargePolicyFormValues,
  | 'target_type'
  | 'target_unit_type_ids'
  | 'target_tower_ids'
  | 'target_section_ids'
  | 'target_floor'
  | 'target_unit_ids'
  | 'target_feature_ids'
  | 'target_unit_id'
>

/**
 * Construye el target_selector del lote inicial de la política (cobro directo o
 * periódico). Tipo/torre/sección/característica son MULTISELECT (arrays) — igual que el
 * lote — por eso NO pasa `floorIncludesTowers`: 'by_floor' (legacy) = ese piso en todas
 * las torres. El mapeo a las keys del backend TargetResolver vive en
 * `shared/utils/targetSelector` (fuente única).
 */
export function buildPolicyTargetSelector(
  values: PolicyTargetFields
): Record<string, unknown> | null {
  return buildResolverSelector(values.target_type, {
    unitType: values.target_unit_type_ids,
    tower: values.target_tower_ids,
    section: values.target_section_ids,
    floor: values.target_floor,
    feature: values.target_feature_ids,
    unitId: values.target_unit_id,
    unitIds: values.target_unit_ids,
  })
}
