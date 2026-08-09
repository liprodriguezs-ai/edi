import type { IMenuSystem } from '@/shared/interfaces/MenuSystem'

/**
 * Determina si una ruta de módulo (ej. '/package') está presente en el menú
 * dinámico del tenant. La respuesta de `/menu` ya codifica las tres capas de
 * acceso (plan del tenant × módulo habilitado × permisos del usuario), así que
 * es LA señal para gatear fetches de módulos opcionales/add-on desde el
 * frontend sin disparar 403 "Módulo no habilitado para este tenant".
 */
export function menuContainsRoute(systems: IMenuSystem[], route: string): boolean {
  return systems.some((system) =>
    system.items.some((item) => {
      if (item.type === 'module') {
        return item.route === route
      }
      return item.modules.some((mod) => mod.route === route)
    }),
  )
}
