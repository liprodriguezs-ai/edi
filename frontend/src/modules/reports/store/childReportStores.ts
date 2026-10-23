import type { IEdiConcentrateRow, IGeneralReportRow } from '@/modules/reports/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useEdiConcentrateStore = createEntityStore<IEdiConcentrateRow>('ediConcentrateReport')

export const useGeneralReportStore = createEntityStore<IGeneralReportRow>('generalReport')
