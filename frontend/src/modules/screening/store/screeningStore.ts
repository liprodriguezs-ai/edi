import type { IScreening } from '@/modules/screening/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

/** List/detail read surfaces only (design D7) — the wizard uses screeningDraftStore. */
export const useScreeningStore = createEntityStore<IScreening>('screening')
