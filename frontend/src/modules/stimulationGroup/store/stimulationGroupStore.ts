import type { IStimulationGroup } from '@/modules/stimulationGroup/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useStimulationGroupStore = createEntityStore<IStimulationGroup>('stimulationGroup')
