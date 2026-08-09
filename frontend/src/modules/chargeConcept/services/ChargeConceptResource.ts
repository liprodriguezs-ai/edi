import type { IChargeConcept } from '@/modules/chargeConcept/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ChargeConceptResource = createCrudApi<IChargeConcept, Record<string, unknown>>({
  basePath: '/charge_concept',
})
