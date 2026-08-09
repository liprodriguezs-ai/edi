import type { IChargeConcept } from '@/modules/chargeConcept/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useChargeConceptStore = createEntityStore<IChargeConcept>('chargeConcept')
