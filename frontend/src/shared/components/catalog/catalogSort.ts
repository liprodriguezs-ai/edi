import { CatalogType } from '@/shared/hooks/useCatalogs'

/**
 * Columna de orden por catálogo cuando un campo `type: 'catalog'` se resuelve
 * vía POST /selects (ApiHandler).
 *
 * El default es `name` porque casi todos los catálogos la tienen. PERO algunos
 * NO: `unit` se etiqueta por torre + número y su tabla NO tiene columna `name`
 * — pedir `_sort: 'name'` revienta con `SQLSTATE[42703] column unit.name does
 * not exist` (HTTP 500). Esos catálogos van acá con su columna real.
 */
export const CATALOG_SORT_BY: Partial<Record<CatalogType, string>> = {
  [CatalogType.UNIT]: 'number',
}

/** Columna de orden a usar en /selects para un catálogo (default `name`). */
export function catalogSortField(catalog: CatalogType): string {
  return CATALOG_SORT_BY[catalog] ?? 'name'
}
