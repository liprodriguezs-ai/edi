/**
 * Tests del builder COMPARTIDO de target_selector (fuente única del mapeo a las keys
 * del backend TargetResolver). Cubre el modo SINGLE (Cargos: id suelto → [id]), el modo
 * MULTI (Chat: arreglo tal cual), los tipos sin selector (all/by_share/board/desconocido
 * → null) y la divergencia de by_floor (con y sin tower_ids).
 */
import { describe, expect, it } from 'vitest'
import { buildTargetSelector } from './targetSelector'

describe('buildTargetSelector — tipos sin selector → null', () => {
  it('devuelve null para "all"', () => {
    expect(buildTargetSelector('all', {})).toBeNull()
  })

  it('devuelve null para "by_share" (Cargos)', () => {
    expect(buildTargetSelector('by_share', {})).toBeNull()
  })

  it('devuelve null para "board" (Chat)', () => {
    expect(buildTargetSelector('board', {})).toBeNull()
  })

  it('devuelve null para "with_vehicles" (sin selector, como "all")', () => {
    expect(buildTargetSelector('with_vehicles', {})).toBeNull()
  })

  it('devuelve null para un tipo desconocido', () => {
    expect(buildTargetSelector('direct', {})).toBeNull()
  })
})

describe('buildTargetSelector — modo SINGLE (id suelto → [id])', () => {
  it('by_type envuelve el id suelto en unit_type_ids[]', () => {
    expect(buildTargetSelector('by_type', { unitType: 'ut-1' })).toEqual({
      unit_type_ids: ['ut-1'],
    })
  })

  it('by_tower envuelve el id suelto en tower_ids[]', () => {
    expect(buildTargetSelector('by_tower', { tower: 't-1' })).toEqual({ tower_ids: ['t-1'] })
  })

  it('by_section envuelve el id suelto en section_ids[]', () => {
    expect(buildTargetSelector('by_section', { section: 's-1' })).toEqual({ section_ids: ['s-1'] })
  })

  it('by_feature envuelve el id suelto en unit_feature_ids[]', () => {
    expect(buildTargetSelector('by_feature', { feature: 'f-1' })).toEqual({
      unit_feature_ids: ['f-1'],
    })
  })

  it('un campo single vacío produce un arreglo vacío (no la key singular)', () => {
    expect(buildTargetSelector('by_tower', { tower: undefined })).toEqual({ tower_ids: [] })
    expect(buildTargetSelector('by_type', { unitType: '' })).toEqual({ unit_type_ids: [] })
    expect(buildTargetSelector('by_section', { section: undefined })).toEqual({ section_ids: [] })
  })
})

describe('buildTargetSelector — modo MULTI (arreglo tal cual)', () => {
  it('by_tower pasa el arreglo de ids tal cual', () => {
    expect(buildTargetSelector('by_tower', { tower: ['t1', 't2'] })).toEqual({
      tower_ids: ['t1', 't2'],
    })
  })

  it('by_section pasa el arreglo de ids tal cual', () => {
    expect(buildTargetSelector('by_section', { section: ['s1', 's2'] })).toEqual({
      section_ids: ['s1', 's2'],
    })
  })

  it('by_feature pasa el arreglo de ids tal cual', () => {
    expect(buildTargetSelector('by_feature', { feature: ['f1', 'f2'] })).toEqual({
      unit_feature_ids: ['f1', 'f2'],
    })
  })

  it('by_type acepta arreglo (Chat usa id único, pero el builder no asume forma)', () => {
    expect(buildTargetSelector('by_type', { unitType: ['ut-1'] })).toEqual({
      unit_type_ids: ['ut-1'],
    })
  })
})

describe('buildTargetSelector — by_floor (divergencia Cargos vs Chat)', () => {
  it('Cargos (sin floorIncludesTowers): solo floors, el piso aplica a todas las torres', () => {
    expect(buildTargetSelector('by_floor', { floor: '3' })).toEqual({ floors: ['3'] })
  })

  it('Cargos: ignora cualquier torre presente cuando floorIncludesTowers es false', () => {
    expect(buildTargetSelector('by_floor', { tower: 't-9', floor: '3' })).toEqual({ floors: ['3'] })
  })

  it('Chat (floorIncludesTowers): emite tower_ids + floors (pisos dentro de torres)', () => {
    expect(
      buildTargetSelector(
        'by_floor',
        { tower: ['t1'], floor: ['3', '4'] },
        { floorIncludesTowers: true }
      )
    ).toEqual({ tower_ids: ['t1'], floors: ['3', '4'] })
  })
})

describe('buildTargetSelector — single_unit y manual', () => {
  it('single_unit emite el escalar unit_id', () => {
    expect(buildTargetSelector('single_unit', { unitId: 'u-007' })).toEqual({ unit_id: 'u-007' })
  })

  it('manual emite la lista de unit_ids tal cual', () => {
    const ids = ['u1', 'u2', 'u3']
    expect(buildTargetSelector('manual', { unitIds: ids })).toEqual({ unit_ids: ids })
  })

  it('manual sin ids produce unit_ids vacío', () => {
    expect(buildTargetSelector('manual', {})).toEqual({ unit_ids: [] })
  })
})
