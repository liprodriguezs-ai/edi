import { Skeleton } from 'boneyard-js/react'

/**
 * Estado de carga a nivel de página (detalle/formulario): skeleton en canto vivo
 * que insinúa el encabezado y el panel de contenido. Reemplaza el "Cargando…" en
 * texto plano que dejaba la página en blanco con una línea gris arriba a la izquierda.
 */
export function PageLoader() {
  return (
    <Skeleton name="page-loader" loading>
      <div className="space-y-6">
        <div className="space-y-2">
          <div className="h-7 w-56 bg-muted" />
          <div className="h-4 w-72 bg-muted" />
        </div>
        <div className="h-64 border border-border bg-card" />
      </div>
    </Skeleton>
  )
}
