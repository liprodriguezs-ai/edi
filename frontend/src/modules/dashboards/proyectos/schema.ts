import type { IMoney } from '@/shared/utils/money'

/**
 * Tablero: Proyectos Comunitarios (funding summary)
 * Endpoint: GET /projects/funding-summary
 * Los montos llegan serializados como Money ({amount, currency, formatted}).
 */

export interface IProyectoRow {
  /** UUID del proyecto */
  id: string
  /** Título del proyecto */
  title: string
  /** Meta de financiamiento */
  goal: IMoney
  /** Monto recaudado */
  raised: IMoney
  /** Porcentaje de avance (0-100, entero; 0 cuando goal=0) */
  pct: number
  /** Clave estable del estado, ej. "active", "fully_funded" */
  status_key: string
  /** Nombre legible del estado en español */
  status_label: string
}

export interface IProyectoByStatus {
  /** Clave estable del estado */
  status_key: string
  /** Nombre legible del estado en español */
  label: string
  /** Número de proyectos en este estado */
  count: number
}

export interface IProyectosTotals {
  /** Número de proyectos activos */
  active_count: number
  /** Suma de metas de todos los proyectos */
  goal: IMoney
  /** Suma de montos recaudados */
  raised: IMoney
  /** Porcentaje de avance global (0-100, entero; 0 cuando goal=0) */
  pct: number
}

/** Payload completo del endpoint /projects/funding-summary */
export interface IProyectosPayload {
  projects: IProyectoRow[]
  totals: IProyectosTotals
  by_status: IProyectoByStatus[]
}

/**
 * Row adapter for ResponsiveDataTable — extends IProyectoRow fields with
 * the index signature required by IEntity.
 */
export interface IProyectoTableRow {
  id: string
  title: string
  raised: IMoney
  goal: IMoney
  pct: number
  status_label: string
  [key: string]: unknown
}
