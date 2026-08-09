import { describe, expect, it } from 'vitest'
import { CatalogType } from '@/shared/hooks/useCatalogs'
import { catalogSortField } from '@/shared/components/catalog/catalogSort'

describe('catalogSortField', () => {
  it('ordena unit por number — la tabla unit no tiene columna name (evita el 500 de /selects)', () => {
    expect(catalogSortField(CatalogType.UNIT)).toBe('number')
  })

  it('cae a name por default para catálogos que sí tienen columna name', () => {
    expect(catalogSortField(CatalogType.CHARGE_STATUS)).toBe('name')
    expect(catalogSortField(CatalogType.PAYMENT_METHOD)).toBe('name')
  })
})
