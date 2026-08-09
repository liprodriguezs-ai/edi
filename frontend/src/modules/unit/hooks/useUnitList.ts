import type { IUnit } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { useUnitStore } from '@/modules/unit/store/unitStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useUnitList() {
  return useBaseList<IUnit>({
    store: useUnitStore,
    resource: UnitResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'number', direction: 'asc' }],
      includes: [
        { relation: 'unitType' },
        { relation: 'unitStatus' },
        { relation: 'tower' },
        { relation: 'section' },
      ],
    },
  })
}
