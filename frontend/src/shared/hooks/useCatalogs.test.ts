import { CatalogType } from '@/shared/hooks/useCatalogs'
import { describe, expect, it } from 'vitest'

/** JS mirror of Laravel's Str::studly('snake_case') -> 'SnakeCase'. */
function studly(value: string): string {
  return value
    .split(/[_-]/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

/**
 * edi-stimulation-sessions (design D12) — CatalogType members are
 * string-coupled to AngularController::$mapperSelects via Str::studly. A
 * mismatch here leaves the corresponding dropdown silently empty (no error).
 * This test proves every new member studly()-resolves to the exact
 * PascalCase key registered in $mapperSelects.
 */
describe('CatalogType — Str::studly coupling with AngularController::$mapperSelects', () => {
  const expectedMapperKeys: Record<string, string> = {
    [CatalogType.STIMULATION_DYNAMIC]: 'StimulationDynamic',
    [CatalogType.BODY_ZONE]: 'BodyZone',
    [CatalogType.MASSAGE_MOVEMENT]: 'MassageMovement',
    [CatalogType.DIDACTIC_MATERIAL]: 'DidacticMaterial',
    [CatalogType.SONG]: 'Song',
    [CatalogType.STIMULATION_GROUP_TYPE]: 'StimulationGroupType',
    [CatalogType.STIMULATION_AREA]: 'StimulationArea',
    [CatalogType.ATTENDANCE_PAYMENT_STATUS]: 'AttendancePaymentStatus',
    [CatalogType.STIMULATION_GROUP]: 'StimulationGroup',
  }

  it.each(Object.entries(expectedMapperKeys))('%s studly()-resolves to %s', (catalogValue, expectedKey) => {
    expect(studly(catalogValue)).toBe(expectedKey)
  })
})
