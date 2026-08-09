import { useCallback } from 'react'

import { useSidebar } from '@/components/ui/sidebar'

/**
 * Cierra el sidebar cuando estamos en móvil.
 *
 * En móvil el sidebar de shadcn se renderiza como un `Sheet` que tapa la
 * pantalla completa, y `SidebarMenuButton` NO lo cierra al navegar: el bloque
 * oficial de shadcn simplemente no lo contempla. Resultado: el usuario tocaba
 * una opción, la ruta cambiaba por detrás y el menú se quedaba abierto encima.
 *
 * En desktop el sidebar es fijo y no debe cerrarse, así que ahí es un no-op.
 *
 * Se aplica a los links que NAVEGAN. El disparador de un grupo colapsable
 * (ej. "Catálogos") no lo usa a propósito: ese sólo despliega el submenú.
 */
export function useCloseMobileSidebar(): () => void {
  const { isMobile, setOpenMobile } = useSidebar()

  return useCallback(() => {
    if (isMobile) {
      setOpenMobile(false)
    }
  }, [isMobile, setOpenMobile])
}
