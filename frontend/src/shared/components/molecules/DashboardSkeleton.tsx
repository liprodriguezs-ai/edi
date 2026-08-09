/**
 * Bloques reutilizables de skeleton para los 5 tableros contables.
 * Importar solo los que cada tablero necesite.
 *
 * Reglas:
 *  - Siempre usar <Skeleton> de boneyard-js/react — NUNCA animate-pulse ni shadcn Skeleton.
 *  - Las clases de grid/tamaño deben coincidir exactamente con el layout real de cada tablero
 *    para evitar layout-shift cuando los datos llegan.
 */
import { Skeleton } from 'boneyard-js/react'

// ---------------------------------------------------------------------------
// KpiRowSkeleton
// Replica: grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-{cols}
// ---------------------------------------------------------------------------

interface KpiRowSkeletonProps {
  /** Número de tarjetas KPI (default 4). */
  count?: number
}

export function KpiRowSkeleton({ count = 4 }: KpiRowSkeletonProps) {
  return (
    <Skeleton name="dashboard-kpi-row" loading>
      <div
        className="mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4"
        aria-busy="true"
        aria-label="Cargando indicadores"
      >
        {Array.from({ length: count }).map((_, i) => (
          <div
            key={i}
            className="rounded-[14px] border border-border bg-card p-4 shadow-sm"
          >
            {/* Swatch + label */}
            <div className="flex items-center gap-2">
              <div className="h-[7px] w-[7px] rounded-sm bg-muted/40" />
              <div className="h-3 w-24 rounded bg-muted/40" />
            </div>
            {/* Value */}
            <div className="mt-2.5 h-8 w-32 rounded bg-muted/40" />
            {/* Detail */}
            <div className="mt-2 h-3 w-40 rounded bg-muted/30" />
          </div>
        ))}
      </div>
    </Skeleton>
  )
}

// ---------------------------------------------------------------------------
// ChartCardSkeleton
// Replica la tarjeta de gráfica: rounded-[14px] border bg-card p-4 shadow-sm
// ---------------------------------------------------------------------------

interface ChartCardSkeletonProps {
  /** Alto del área de la gráfica en px (debe coincidir con el `height` del chart real). */
  height?: number
  /** Ancho de la barra de título simulada (default 'w-40'). */
  titleWidth?: string
}

export function ChartCardSkeleton({ height = 260, titleWidth = 'w-40' }: ChartCardSkeletonProps) {
  return (
    <div
      className="overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm"
      aria-busy="true"
      aria-label="Cargando gráfica"
    >
      {/* Título */}
      <div className={`mb-4 h-[18px] rounded bg-muted/40 ${titleWidth}`} />
      {/* Área de la gráfica */}
      <div
        className="rounded-md bg-muted/20"
        style={{ height }}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------
// TableSkeletonRows
// Replica la estructura de una tabla con N filas placeholder.
// Se coloca dentro del contenedor de tarjeta existente del tablero.
// ---------------------------------------------------------------------------

interface TableSkeletonRowsProps {
  /** Número de filas a mostrar (default 5). */
  rows?: number
  /** Número de columnas a mostrar (default 3). */
  cols?: number
}

export function TableSkeletonRows({ rows = 5, cols = 3 }: TableSkeletonRowsProps) {
  return (
    <div
      className="overflow-hidden rounded-md border border-border"
      aria-busy="true"
      aria-label="Cargando tabla"
    >
      {/* Cabecera */}
      <div className="flex gap-4 border-b border-border bg-muted/10 px-4 py-3">
        {Array.from({ length: cols }).map((_col, i) => (
          <div key={i} className="h-3 flex-1 rounded bg-muted/40" />
        ))}
      </div>
      {/* Filas */}
      {Array.from({ length: rows }).map((_row, r) => (
        <div
          key={r}
          className="flex gap-4 border-b border-border/50 px-4 py-3.5 last:border-b-0"
        >
          {Array.from({ length: cols }).map((_cell, c) => (
            <div
              key={c}
              className={`h-3.5 flex-1 rounded bg-muted/30 ${c === 0 ? 'w-1/2 flex-[2]' : ''}`}
            />
          ))}
        </div>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// AccountsListSkeleton
// Replica el panel de cuentas de Tesorería: filas nombre + saldo, total al final.
// ---------------------------------------------------------------------------

interface AccountsListSkeletonProps {
  rows?: number
}

export function AccountsListSkeleton({ rows = 4 }: AccountsListSkeletonProps) {
  return (
    <div aria-busy="true" aria-label="Cargando cuentas bancarias">
      {Array.from({ length: rows }).map((_, i) => (
        <div
          key={i}
          className="flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0"
        >
          <div className="h-4 flex-1 rounded bg-muted/40" />
          <div className="h-5 w-24 rounded bg-muted/40" />
        </div>
      ))}
      {/* Fila de total */}
      <div className="flex items-center justify-between border-t border-border bg-[var(--surface-2,#FAF9F5)] px-4 py-3.5">
        <div className="h-3 w-10 rounded bg-muted/30" />
        <div className="h-5 w-28 rounded bg-muted/40" />
      </div>
    </div>
  )
}
