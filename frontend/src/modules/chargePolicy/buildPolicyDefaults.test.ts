import { describe, expect, it } from 'vitest'
import { buildPolicyDefaults } from '@/modules/chargePolicy/buildPolicyDefaults'
import {
  AMOUNT_MODE,
  PERIODICITY,
  buildPolicyTargetSelector,
  type IChargePolicy,
  type TargetType,
} from '@/modules/chargePolicy/schema'

/**
 * Hidratación del alcance en EDICIÓN: `buildPolicyDefaults` reconstruye los multiselect
 * (`target_unit_type_ids`/`target_tower_ids`/`target_feature_ids`) desde el
 * `target_selector` del lote inicial (`is_initial = true`), vía el helper interno
 * `selectorIds`. Estos tests cubren la lectura de ambas formas del selector:
 *  - legacy SINGLE string (`{ unit_type_ids: 'abc' }`) → `['abc']`
 *  - array multi (`{ tower_ids: ['t1','t2'] }`) → `['t1','t2']`
 *  - vacío/null → arrays vacíos
 *  - el filtro de no-strings (un valor no-string en el array se descarta)
 *
 * Nota de contrato: el round-trip (build → hydrate → build) SIEMPRE emite arrays de
 * strings, porque el builder (`buildPolicyTargetSelector`) parte de los campos del form,
 * que son `string[]`. El filtro de no-strings solo protege contra datos legacy corruptos.
 */

const NOW = new Date(2026, 0, 15)

/** Política mínima con un lote inicial que carga el target_selector indicado. */
function policyWithInitialBatch(
  targetType: TargetType,
  selector: Record<string, unknown> | null
): IChargePolicy {
  return {
    id: 'p-1',
    charge_concept_id: 'c-1',
    name: 'Mantenimiento',
    amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED,
    periodicity_id: PERIODICITY.MONTHLY,
    is_active: true,
    charge_batches: [
      {
        id: 'b-1',
        is_initial: true,
        target_type: targetType,
        target_selector: selector,
        issued_at: '2026-01-01',
        due_date: '2026-01-31',
      },
    ],
  } as IChargePolicy
}

describe('buildPolicyDefaults — hidratación del alcance (selectorIds / batchScopeFromPolicy)', () => {
  it('hidrata un selector legacy SINGLE string a un array de un elemento', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      policyWithInitialBatch('by_type', { unit_type_ids: 'abc' }),
      NOW
    )
    expect(defaults.target_type).toBe('by_type')
    expect(defaults.target_unit_type_ids).toEqual(['abc'])
  })

  it('hidrata un selector array multi tal cual (by_tower)', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      policyWithInitialBatch('by_tower', { tower_ids: ['t1', 't2'] }),
      NOW
    )
    expect(defaults.target_type).toBe('by_tower')
    expect(defaults.target_tower_ids).toEqual(['t1', 't2'])
  })

  it('hidrata secciones multi tal cual (by_section) y round-tripea al mismo selector', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      policyWithInitialBatch('by_section', { section_ids: ['s1', 's2'] }),
      NOW
    )
    expect(defaults.target_type).toBe('by_section')
    expect(defaults.target_section_ids).toEqual(['s1', 's2'])
    expect(buildPolicyTargetSelector(defaults)).toEqual({ section_ids: ['s1', 's2'] })
  })

  it('hidrata características multi (by_feature, semántica OR en backend)', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      policyWithInitialBatch('by_feature', { unit_feature_ids: ['f1', 'f2'] }),
      NOW
    )
    expect(defaults.target_feature_ids).toEqual(['f1', 'f2'])
  })

  it('devuelve arrays vacíos cuando el selector es null (target_type "all")', () => {
    const defaults = buildPolicyDefaults('c-1', policyWithInitialBatch('all', null), NOW)
    expect(defaults.target_unit_type_ids).toEqual([])
    expect(defaults.target_tower_ids).toEqual([])
    expect(defaults.target_section_ids).toEqual([])
    expect(defaults.target_feature_ids).toEqual([])
  })

  it('descarta valores no-string del array (datos legacy corruptos)', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      // mezcla string + no-string: solo los strings sobreviven.
      policyWithInitialBatch('by_feature', { unit_feature_ids: ['f1', 123, null, 'f2'] }),
      NOW
    )
    expect(defaults.target_feature_ids).toEqual(['f1', 'f2'])
  })

  it('sin política (alta) devuelve arrays de alcance vacíos', () => {
    const defaults = buildPolicyDefaults('c-1', null, NOW)
    expect(defaults.target_type).toBe('all')
    expect(defaults.target_unit_type_ids).toEqual([])
    expect(defaults.target_tower_ids).toEqual([])
    expect(defaults.target_feature_ids).toEqual([])
  })

  it('sin lote inicial devuelve alcance vacío', () => {
    const policy = policyWithInitialBatch('by_tower', { tower_ids: ['t1'] })
    policy.charge_batches = [] // ningún lote is_initial
    const defaults = buildPolicyDefaults('c-1', policy, NOW)
    expect(defaults.target_type).toBe('all')
    expect(defaults.target_tower_ids).toEqual([])
  })

  it('round-trip: hidratar y reconstruir emite el mismo array de strings', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      policyWithInitialBatch('by_tower', { tower_ids: ['t1', 't2'] }),
      NOW
    )
    const rebuilt = buildPolicyTargetSelector(defaults)
    expect(rebuilt).toEqual({ tower_ids: ['t1', 't2'] })
  })
})

describe('buildPolicyDefaults — regla EVENTUAL/directa (bug #2: fallback a is_initial=false)', () => {
  /**
   * Una regla eventual NO tiene template (`is_initial=true`) — el único lote que genera
   * es directo (`is_initial=false`). Antes de este fix, `batchScopeFromPolicy` solo
   * buscaba `is_initial===true` y caía en `emptyBatchScope('')`, perdiendo el alcance y
   * las fechas reales al abrir el form de edición.
   */
  function policyWithDirectBatch(
    targetType: TargetType,
    selector: Record<string, unknown> | null
  ): IChargePolicy {
    return {
      id: 'p-2',
      charge_concept_id: 'c-1',
      name: 'Cuota extraordinaria',
      amount_mode_id: AMOUNT_MODE.FIXED_REQUIRED,
      periodicity_id: PERIODICITY.EVENTUAL,
      is_active: true,
      charge_batches: [
        {
          id: 'b-2',
          is_initial: false,
          target_type: targetType,
          target_selector: selector,
          issued_at: '2026-03-01',
          due_date: '2026-03-15',
        },
      ],
    } as IChargePolicy
  }

  it('hidrata el alcance real del único lote directo (is_initial=false), no un alcance vacío', () => {
    const defaults = buildPolicyDefaults(
      'c-1',
      policyWithDirectBatch('by_tower', { tower_ids: ['t1', 't2'] }),
      NOW
    )
    expect(defaults.target_type).toBe('by_tower')
    expect(defaults.target_tower_ids).toEqual(['t1', 't2'])
  })

  it('hidrata las fechas del cobro (issued_at/due_date) del lote directo', () => {
    const defaults = buildPolicyDefaults('c-1', policyWithDirectBatch('all', null), NOW)
    expect(defaults.issued_at).toBe('2026-03-01')
    expect(defaults.due_date).toBe('2026-03-15')
  })
})
