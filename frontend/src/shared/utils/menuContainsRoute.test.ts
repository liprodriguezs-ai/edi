/**
 * Tests del helper PURO que detecta si una ruta de módulo está presente en el
 * menú dinámico del tenant (señal para gatear fetches de módulos add-on, ej.
 * el widget de Paquetería en el dashboard).
 */
import { describe, expect, it } from 'vitest'
import type { IMenuSystem } from '@/shared/interfaces/MenuSystem'
import { menuContainsRoute } from './menuContainsRoute'

function makeSystem(items: IMenuSystem['items']): IMenuSystem {
  return { id: 'sys-1', name: 'Sistema', slug: 'sistema', icon: null, items }
}

function makeModuleItem(route: string | null) {
  return {
    type: 'module' as const,
    id: `mod-${route ?? 'null'}`,
    name: 'Módulo',
    icon: null,
    route,
    permission_entity: null,
    group: null,
  }
}

describe('menuContainsRoute', () => {
  it('encuentra la ruta en un item top-level type module', () => {
    const systems = [makeSystem([makeModuleItem('/package')])]

    expect(menuContainsRoute(systems, '/package')).toBe(true)
  })

  it('encuentra la ruta dentro de los modules de un item type group', () => {
    const systems = [
      makeSystem([
        {
          type: 'group' as const,
          label: 'Catálogos',
          icon: null,
          modules: [
            { id: 'm1', name: 'Transportistas', icon: null, route: '/carrier', permission_entity: null, group: null },
            { id: 'm2', name: 'Paquetes', icon: null, route: '/package', permission_entity: null, group: null },
          ],
        },
      ]),
    ]

    expect(menuContainsRoute(systems, '/package')).toBe(true)
  })

  it('devuelve false cuando la ruta no está en ningún sistema', () => {
    const systems = [makeSystem([makeModuleItem('/expense'), makeModuleItem(null)])]

    expect(menuContainsRoute(systems, '/package')).toBe(false)
  })

  it('devuelve false con menú vacío (aún cargando)', () => {
    expect(menuContainsRoute([], '/package')).toBe(false)
  })
})
