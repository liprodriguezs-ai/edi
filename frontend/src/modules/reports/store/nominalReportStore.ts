import type { IScreening } from '@/modules/screening/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useNominalReportStore = createEntityStore<IScreening>('nominalReport')
