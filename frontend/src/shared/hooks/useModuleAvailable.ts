import { useMemo } from 'react'

import { useMenu } from '@/shared/hooks/useMenu'
import { menuContainsRoute } from '@/shared/utils/menuContainsRoute'

/**
 * Indica si un módulo (por su ruta de frontend, ej. '/package') está disponible
 * para el tenant y el usuario actuales según el menú dinámico. Úsalo para
 * gatear queries de módulos opcionales/add-on (evita 403 de módulo no
 * habilitado y peticiones que no corresponden al plan del tenant).
 *
 * Devuelve `false` mientras el menú carga — los consumidores deben tratarlo
 * como "todavía no disponible" (no fetch), no como error.
 */
export function useModuleAvailable(route: string): boolean {
  const { systems } = useMenu()

  return useMemo(() => menuContainsRoute(systems, route), [systems, route])
}
