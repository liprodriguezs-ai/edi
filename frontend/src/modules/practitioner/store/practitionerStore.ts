import type { IPractitionerProfile } from '@/modules/practitioner/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const usePractitionerStore = createEntityStore<IPractitionerProfile>('practitioner')
