/**
 * Tests del helper PURO que agrupa los subItems de un item colapsable del sidebar
 * (ej. "Catálogos") por módulo dueño (`group`). Cubre: agrupación + orden alfabético
 * con 2+ grupos distintos, "sin agrupación" (null) con 0-1 grupo distinto, e items
 * sin `group` cayendo en el bloque final "Otros".
 */
import { describe, expect, it } from 'vitest'
import type { NavSubItem } from '@/shared/hooks/useFilteredNavItems'
import { groupNavSubItems } from './groupNavSubItems'

function makeSubItem(title: string, group?: string): NavSubItem {
  return { title, url: `/${title.toLowerCase().replace(/\s+/g, '-')}`, group }
}

describe('groupNavSubItems — múltiples grupos', () => {
  it('agrupa por group y ordena grupos e items alfabéticamente (es)', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Tipos de Proveedor', 'Proveedores'),
      makeSubItem('Categorías de Egreso', 'Tesorería'),
      makeSubItem('Puestos de Empleado', 'RRHH'),
      makeSubItem('Tipos de Servicio', 'Proveedores'),
      makeSubItem('Frecuencia de Pago', 'RRHH'),
      makeSubItem('Propósitos de Cuenta', 'Tesorería'),
    ]

    const result = groupNavSubItems(subItems)

    expect(result).not.toBeNull()
    expect(result?.map((block) => block.label)).toEqual(['Proveedores', 'RRHH', 'Tesorería'])
    expect(result?.find((block) => block.label === 'RRHH')?.items.map((i) => i.title)).toEqual([
      'Frecuencia de Pago',
      'Puestos de Empleado',
    ])
    expect(result?.find((block) => block.label === 'Proveedores')?.items.map((i) => i.title)).toEqual([
      'Tipos de Proveedor',
      'Tipos de Servicio',
    ])
    expect(result?.find((block) => block.label === 'Tesorería')?.items.map((i) => i.title)).toEqual([
      'Categorías de Egreso',
      'Propósitos de Cuenta',
    ])
  })

  it('pone los items sin group en un bloque final "Otros", tras los grupos ordenados', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Zeta sin grupo'),
      makeSubItem('Tipos de Servicio', 'Proveedores'),
      makeSubItem('Puestos de Empleado', 'RRHH'),
      makeSubItem('Alfa sin grupo'),
    ]

    const result = groupNavSubItems(subItems)

    expect(result).not.toBeNull()
    expect(result?.map((block) => block.label)).toEqual(['Proveedores', 'RRHH', 'Otros'])
    expect(result?.at(-1)?.items.map((i) => i.title)).toEqual(['Alfa sin grupo', 'Zeta sin grupo'])
  })

  it('fusiona los items sin group dentro de un grupo real "Otros" sin duplicar la etiqueta', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Zeta sin grupo'),
      makeSubItem('Catálogo misceláneo', 'Otros'),
      makeSubItem('Puestos de Empleado', 'RRHH'),
    ]

    const result = groupNavSubItems(subItems)

    expect(result).not.toBeNull()
    expect(result?.map((block) => block.label)).toEqual(['Otros', 'RRHH'])
    expect(result?.find((block) => block.label === 'Otros')?.items.map((i) => i.title)).toEqual([
      'Catálogo misceláneo',
      'Zeta sin grupo',
    ])
  })

  it('normaliza con trim los valores de group para no partir un módulo en dos bloques', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Puestos de Empleado', ' RRHH'),
      makeSubItem('Frecuencia de Pago', 'RRHH '),
      makeSubItem('Tipos de Servicio', 'Proveedores'),
    ]

    const result = groupNavSubItems(subItems)

    expect(result).not.toBeNull()
    expect(result?.map((block) => block.label)).toEqual(['Proveedores', 'RRHH'])
    expect(result?.find((block) => block.label === 'RRHH')?.items.map((i) => i.title)).toEqual([
      'Frecuencia de Pago',
      'Puestos de Empleado',
    ])
  })
})

describe('groupNavSubItems — sin agrupación (0 o 1 grupo distinto)', () => {
  it('devuelve null cuando ningún subItem tiene group', () => {
    const subItems: NavSubItem[] = [makeSubItem('Torres'), makeSubItem('Secciones')]

    expect(groupNavSubItems(subItems)).toBeNull()
  })

  it('devuelve null cuando todos los subItems comparten el mismo group', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Torres', 'Fraccionamiento'),
      makeSubItem('Secciones', 'Fraccionamiento'),
    ]

    expect(groupNavSubItems(subItems)).toBeNull()
  })

  it('devuelve null cuando solo hay un valor de group distinto mezclado con items sin group', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Torres', 'Fraccionamiento'),
      makeSubItem('Secciones'),
    ]

    expect(groupNavSubItems(subItems)).toBeNull()
  })

  it('ignora valores de group vacíos o solo espacios como si no tuvieran group', () => {
    const subItems: NavSubItem[] = [
      makeSubItem('Torres', ''),
      makeSubItem('Secciones', '   '),
    ]

    expect(groupNavSubItems(subItems)).toBeNull()
  })
})
