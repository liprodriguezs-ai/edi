import type { ISection } from '@/modules/section/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useSectionStore = createEntityStore<ISection>('section')
