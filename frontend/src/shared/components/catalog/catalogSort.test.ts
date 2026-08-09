import { describe, expect, it } from 'vitest'
import { CatalogType } from '@/shared/hooks/useCatalogs'
import { catalogSortField } from '@/shared/components/catalog/catalogSort'

describe('catalogSortField', () => {
  it('cae a name por default para catálogos que sí tienen columna name', () => {
    expect(catalogSortField(CatalogType.COUNTRY)).toBe('name')
    expect(catalogSortField(CatalogType.ROLE)).toBe('name')
  })

  // CATALOG_SORT_BY está vacío: hoy todos los catálogos tienen columna `name`.
  // Cuando EDI agregue uno que no la tenga (se etiqueta por otra columna),
  // registrarlo ahí y cubrirlo acá — pedir `_sort: 'name'` sobre una tabla sin
  // esa columna devuelve HTTP 500, no un error de compilación.
})
