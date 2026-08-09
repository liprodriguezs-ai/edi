import type { KeyboardEvent, ReactNode } from 'react'
import { Table, TableBody } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import type { IEntity } from '@/shared/interfaces/Entity'

/**
 * Un par etiqueta/valor que se muestra como fila de la card en mobile (< md).
 * La etiqueta DEBE coincidir con el texto del <TableHead> correspondiente.
 */
export interface ResponsiveField {
  /** Etiqueta corta en español, ej. "Tipo". Reusar el MISMO texto del <TableHead>. */
  label: string
  /** Valor ya renderizado (puede ser un pill, dinero formateado, etc.). */
  value: ReactNode
}

/**
 * Breakpoint en el que el componente cambia entre vista de cards (debajo del
 * breakpoint) y vista de tabla (en el breakpoint y superior).
 *
 * - `'md'` (default): cambia en 768px. Suficiente para tablas de hasta ~5 cols.
 * - `'lg'`: cambia en 1024px. Necesario para tablas ANCHAS (≥7 cols) donde
 *   el sidebar desplegado (~285px) no deja espacio suficiente en el rango 768–1024px.
 */
export type ResponsiveBreakpoint = 'md' | 'lg'

/**
 * Clases CSS estáticas por breakpoint para las dos ramas del componente.
 * IMPORTANTE: Tailwind JIT necesita ver strings COMPLETOS en el código fuente —
 * NO construir las clases dinámicamente (ej. `hidden ${bp}:block`).
 */
const BRANCH_CLASSES: Record<ResponsiveBreakpoint, { table: string; cards: string }> = {
  md: { table: 'hidden md:block', cards: 'md:hidden' },
  lg: { table: 'hidden lg:block', cards: 'lg:hidden' },
} as const

export interface ResponsiveDataTableProps<T extends IEntity> {
  /** Las entidades ya cargadas/paginadas por el módulo (sin transformar). */
  rows: T[]
  /** Encabezado de la tabla (md+) — el <TableHeader>…</TableHeader> del módulo. */
  header: ReactNode
  /** Fila de tabla para md+ — el <TableRow>…</TableRow> EXACTO que el módulo ya escribe. */
  renderRow: (entity: T) => ReactNode
  /** Título de la card en mobile (< md), ej. el identificador de la unidad. */
  renderCardTitle: (entity: T) => ReactNode
  /** Pares etiqueta/valor del cuerpo de la card en mobile. */
  renderCardFields: (entity: T) => ResponsiveField[]
  /**
   * Nodo de acciones de la fila (Editar/Eliminar/…). El MISMO que va en la última
   * celda en md+. En mobile se fija en el header de la card (siempre visible).
   * Si la fila no tiene acciones, omitir.
   */
  renderActions?: (entity: T) => ReactNode
  /** Navegación al click de fila/card (ej. ir al detalle). Opcional. */
  onRowClick?: (entity: T) => void
  /** Clave única por fila (default entity.id). */
  getRowKey?: (entity: T) => string
  /**
   * Nodo opcional que se renderiza dentro del contenedor de la tabla desktop (md+),
   * DESPUÉS de la tabla. Útil para pies de tabla como totales que deben vivir
   * dentro del mismo bloque bordeado. En mobile se ignora (el consumidor
   * puede renderizarlo por separado si es necesario).
   */
  footer?: ReactNode
  /**
   * Breakpoint en el que se cambia de cards a tabla.
   * - `'md'` (default, 768px): válido para tablas de hasta ~5–6 columnas.
   * - `'lg'` (1024px): usar para tablas ANCHAS (≥7 cols) donde el sidebar
   *   expandido genera overflow en la banda 768–1024px.
   */
  breakpoint?: ResponsiveBreakpoint
}

/**
 * Wrapper presentacional que expone dos representaciones simultáneas en el DOM:
 * - En el breakpoint (md o lg) y superior: tabla shadcn estándar.
 * - Por debajo del breakpoint: lista de cards con definición (dl/dt/dd).
 *
 * El breakpoint por defecto es `md` (768px). Usar `breakpoint="lg"` (1024px)
 * para tablas anchas (≥7 cols) donde el sidebar expandido (~285px) provoca
 * overflow en la banda 768–1024px.
 *
 * Ambas ramas se montan; CSS elige cuál es visible. Esto evita el flickering
 * de primer render que produce useIsMobile (state inicia undefined/false).
 *
 * El módulo dueño de los datos sigue siendo responsable de: fetcheo, paginación,
 * filtros, permisos, modales. Este componente es SOLO presentacional.
 */
export function ResponsiveDataTable<T extends IEntity>({
  rows,
  header,
  renderRow,
  renderCardTitle,
  renderCardFields,
  renderActions,
  onRowClick,
  getRowKey,
  footer,
  breakpoint = 'lg',
}: ResponsiveDataTableProps<T>): ReactNode {
  const branch = BRANCH_CLASSES[breakpoint]

  return (
    <>
      {/* Desktop: en el breakpoint y superior. Oculto en mobile/tablet via CSS sin JS ni flicker. */}
      <div className={`${branch.table} overflow-hidden rounded-[14px] border border-border bg-card shadow-sm`}>
        <Table>
          {header}
          <TableBody>
            {rows.map((entity) => renderRow(entity))}
          </TableBody>
        </Table>
        {footer}
      </div>

      {/* Mobile/tablet: por debajo del breakpoint. Cards con lista de definición.
          grid-cols-[minmax(0,1fr)]: el track NO crece con el contenido (auto) → la card
          nunca excede el viewport aunque una etiqueta/folio sea largo (fix overflow mobile). */}
      <ul className={`grid grid-cols-[minmax(0,1fr)] gap-3 ${branch.cards}`} role="list">
        {rows.map((entity) => {
          const key = getRowKey ? getRowKey(entity) : entity.id
          const fields = renderCardFields(entity)
          const isClickable = Boolean(onRowClick)

          const handleKeyDown = isClickable
            ? (e: KeyboardEvent<HTMLDivElement>) => {
                if (e.key === 'Enter') {
                  onRowClick!(entity)
                } else if (e.key === ' ') {
                  e.preventDefault()
                  onRowClick!(entity)
                }
              }
            : undefined

          return (
            <li key={key}>
              <div
                className={cn(
                  'bg-card text-card-foreground rounded-xl border shadow-sm overflow-hidden',
                  isClickable && 'cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring',
                )}
                role={isClickable ? 'button' : undefined}
                tabIndex={isClickable ? 0 : undefined}
                onClick={isClickable ? () => onRowClick!(entity) : undefined}
                onKeyDown={handleKeyDown}
              >
                {/* Cabecera de la card: título + acciones */}
                <div className="flex items-start justify-between gap-3 px-4 pt-4 pb-2">
                  <div className="font-medium text-[var(--ink-1)] min-w-0 flex-1 truncate">
                    {renderCardTitle(entity)}
                  </div>
                  {renderActions && (
                    <div
                      className="flex-shrink-0"
                      onClick={(e) => e.stopPropagation()}
                      onKeyDown={(e) => e.stopPropagation()}
                    >
                      {renderActions(entity)}
                    </div>
                  )}
                </div>

                {/* Cuerpo de la card: pares etiqueta/valor */}
                {fields.length > 0 && (
                  <dl className="grid gap-1.5 px-4 pb-4 pt-1">
                    {fields.map((field) => (
                      <div
                        key={field.label}
                        className="flex items-baseline justify-between gap-3"
                      >
                        <dt className="text-[12px] text-[var(--ink-3)] flex-shrink-0">
                          {field.label}
                        </dt>
                        <dd className="text-[13px] text-[var(--ink-2)] text-right min-w-0 break-words">
                          {field.value}
                        </dd>
                      </div>
                    ))}
                  </dl>
                )}
              </div>
            </li>
          )
        })}
      </ul>
    </>
  )
}
