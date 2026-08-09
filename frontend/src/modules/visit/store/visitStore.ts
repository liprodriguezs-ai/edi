import type { IVisit } from '@/modules/visit/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useVisitStore = createEntityStore<IVisit>('visit')
