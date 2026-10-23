import { cn } from '@/lib/utils'
import { Skeleton } from 'boneyard-js/react'

interface TableLoadingStateProps {
  message?: string
  rows?: number
  className?: string
  showSkeletons?: boolean
  mini?: boolean
}

/**
 * Estado de carga de listas: filas skeleton en canto vivo (hairline, sin sombras,
 * gradientes ni glow), coherente con el sistema de diseño. Reemplazó un
 * placeholder con borde punteado, degradados, un color `energy` fuera de la
 * paleta y puntos que rebotaban — ajeno por completo al lenguaje de la app.
 *
 * Las props `message` / `showSkeletons` se conservan por compatibilidad de la
 * firma; ya no cambian el render.
 */
export const TableLoadingState = ({ rows = 5, className, mini = false }: TableLoadingStateProps) => {
  return (
    <Skeleton name="table-loading-state" loading>
      <div className={cn('divide-y divide-border border border-border bg-card', className)}>
        {Array.from({ length: mini ? 3 : rows }).map((_, index) => (
          <div key={index} className="flex items-center gap-4 px-4 py-3.5">
            <div className="h-4 flex-1 bg-muted" />
            <div className="hidden h-4 w-32 bg-muted sm:block" />
            <div className="h-4 w-20 bg-muted" />
          </div>
        ))}
      </div>
    </Skeleton>
  )
}
