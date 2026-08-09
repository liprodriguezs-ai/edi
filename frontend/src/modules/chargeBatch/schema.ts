import type { IChargeConcept } from '@/modules/chargeConcept/schema'
import type { IChargePolicy } from '@/modules/chargePolicy/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { buildTargetSelector as buildResolverSelector } from '@/shared/utils/targetSelector'
import { z } from 'zod'

export const CHARGE_BATCH_STATUS = {
  DRAFT: 'fdfc6680-e268-4d0e-9a86-cfaa000771e7',
  ACTIVE: '69d2ec6a-f18e-4235-be66-de0f8b929e9d',
  CANCELLED: '0c4e48a8-3ff4-4a8e-af81-d09c199003f7',
} as const

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

export interface IChargeBatch extends IEntity {
  charge_policy_id: string
  title: string
  description?: string
  year: number
  month?: number | null
  /**
   * Subdivisión del período dentro de year+month. Su significado depende de la
   * periodicidad de la regla: semana ISO (semanal), 1|2 (quincenal) o día de inicio
   * de la ventana (cada 2 días). `null` para mensual/bimestral/anual/eventual.
   */
  cycle?: number | null
  /**
   * Arranque del período anclado (ANCHORED BILLING PERIODS). `null` en lotes legacy
   * (rejilla calendario pre-cutover) y en la plantilla (is_initial=true). Cuando está
   * presente, `periodLabel()` muestra el rango real en vez de reconstruir mes/año.
   */
  period_start?: string | null
  target_type: TargetType
  target_selector?: Record<string, unknown> | null
  issued_at: string
  due_date: string
  charge_batch_status_id: string
  progress_pct?: number | string | null
  policy?: IChargePolicy | null
  /** Conveniencia: el concepto resuelto vía la política (policy.concept). */
  concept?: IChargeConcept | null
  status?: { id: string; name: string } | null
}

export const chargeBatchFormSchema = z
  .object({
    charge_policy_id: z.string().min(1, 'La programación es requerida'),
    title: z.string().min(1, 'El título es requerido').max(255),
    description: z.string().max(1000).optional(),
    year: z.number().int().min(2000, 'Año inválido').max(2100, 'Año inválido'),
    month: z.number().int().min(1).max(12).nullable(),
    target_type: z.enum(TARGET_TYPES),
    // selección según target_type — se valida en superRefine.
    // tipo/torre/sección/característica son MULTISELECT (arrays). `target_floor` y
    // `target_unit_id` siguen vivos para datos legacy (by_floor / single_unit) aunque
    // esos alcances ya NO se ofrecen en el selector del UI. `with_vehicles` no lleva
    // campo propio: sin selector, igual que `all`.
    target_unit_type_ids: z.array(z.string()).optional(),
    target_tower_ids: z.array(z.string()).optional(),
    target_section_ids: z.array(z.string()).optional(),
    target_floor: z.string().optional(),
    target_unit_ids: z.array(z.string()).optional(),
    target_feature_ids: z.array(z.string()).optional(),
    target_unit_id: z.string().optional(),
    issued_at: z.string().min(1, 'La fecha de generación es requerida'),
    due_date: z.string().min(1, 'La fecha de vencimiento es requerida'),
    activate: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.due_date && values.issued_at && values.due_date < values.issued_at) {
      ctx.addIssue({
        code: 'custom',
        path: ['due_date'],
        message: 'Debe ser posterior a la generación',
      })
    }
    const need = (cond: boolean, path: string, message: string) => {
      if (cond) ctx.addIssue({ code: 'custom', path: [path], message })
    }
    switch (values.target_type) {
      case 'by_type':
        need(
          !values.target_unit_type_ids?.length,
          'target_unit_type_ids',
          'Selecciona al menos un tipo de unidad'
        )
        break
      case 'by_tower':
        need(!values.target_tower_ids?.length, 'target_tower_ids', 'Selecciona al menos una torre')
        break
      case 'by_section':
        need(
          !values.target_section_ids?.length,
          'target_section_ids',
          'Selecciona al menos una sección'
        )
        break
      case 'by_floor':
        need(!values.target_floor?.trim(), 'target_floor', 'Indica el piso')
        break
      case 'manual':
        need(!values.target_unit_ids?.length, 'target_unit_ids', 'Selecciona al menos una unidad')
        break
      case 'by_feature':
        need(
          !values.target_feature_ids?.length,
          'target_feature_ids',
          'Selecciona al menos una característica'
        )
        break
      case 'single_unit':
        need(!values.target_unit_id, 'target_unit_id', 'Selecciona la unidad')
        break
    }
  })

export type ChargeBatchFormValues = z.infer<typeof chargeBatchFormSchema>

/**
 * Construye el target_selector del lote. Tipo/torre/sección/característica son MULTISELECT
 * (arrays); el util ya envuelve/pasa arrays a las keys plurales del TargetResolver
 * (`unit_type_ids[]` / `tower_ids[]` / `section_ids[]` / `unit_feature_ids[]`). NO se pasa
 * `floorIncludesTowers`: 'by_floor' (legacy) = ese piso en todas las torres. El mapeo
 * a las keys del backend vive en `shared/utils/targetSelector` (fuente única — ahí se
 * corrigió el bug de keys singulares vs plurales que dejaba lotes sin generar cargos).
 */
export function buildTargetSelector(values: ChargeBatchFormValues): Record<string, unknown> | null {
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
