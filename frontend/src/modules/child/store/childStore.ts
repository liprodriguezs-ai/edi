import type { IChild } from '@/modules/child/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useChildStore = createEntityStore<IChild>('child')
