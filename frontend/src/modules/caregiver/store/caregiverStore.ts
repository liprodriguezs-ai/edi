import type { ICaregiver } from '@/modules/caregiver/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useCaregiverStore = createEntityStore<ICaregiver>('caregiver')
