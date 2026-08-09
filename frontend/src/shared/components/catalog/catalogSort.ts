import { CatalogType } from '@/shared/hooks/useCatalogs'

/**
 * Columna de orden por catálogo cuando un campo `type: 'catalog'` se resuelve
 * vía POST /selects (ApiHandler).
 *
 * El default es `name` porque casi todos los catálogos la tienen. Un catálogo
 * SIN columna `name` debe registrarse acá con su columna real: pedir
 * `_sort: 'name'` sobre una tabla que no la tiene revienta con
 * `SQLSTATE[42703] column ... does not exist` (HTTP 500).
 */
export const CATALOG_SORT_BY: Partial<Record<CatalogType, string>> = {
  // practitioner_profile has no own `name` column (D6 design note) — its
  // display name comes from the eager-loaded `user` relation instead.
  [CatalogType.PRACTITIONER_PROFILE]: 'license_number',
}

/** Columna de orden a usar en /selects para un catálogo (default `name`). */
export function catalogSortField(catalog: CatalogType): string {
  return CATALOG_SORT_BY[catalog] ?? 'name'
}
