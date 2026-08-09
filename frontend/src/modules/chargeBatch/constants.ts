import type { ChargeBatchFormValues } from '@/modules/chargeBatch/schema'

/** Nombres de mes en español para el formulario de lote de cobro. */
export const MONTH_NAMES = [
  'Enero',
  'Febrero',
  'Marzo',
  'Abril',
  'Mayo',
  'Junio',
  'Julio',
  'Agosto',
  'Septiembre',
  'Octubre',
  'Noviembre',
  'Diciembre',
]

/**
 * Construye los valores iniciales del formulario de lote de cobro.
 * Pura — sin efectos secundarios. Testeable con fecha fija.
 */
export function buildChargeBatchDefaults(now: Date): ChargeBatchFormValues {
  return {
    charge_policy_id: '',
    title: '',
    description: '',
    year: now.getFullYear(),
    month: now.getMonth() + 1,
    target_type: 'all',
    target_unit_type_ids: [],
    target_tower_ids: [],
    target_section_ids: [],
    target_floor: '',
    target_unit_ids: [],
    target_feature_ids: [],
    target_unit_id: undefined,
    issued_at: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`,
    due_date: '',
    activate: true,
  }
}
