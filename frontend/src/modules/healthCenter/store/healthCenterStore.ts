import type { IHealthCenter } from '@/modules/healthCenter/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useHealthCenterStore = createEntityStore<IHealthCenter>('healthCenter')
