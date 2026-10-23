import type { IStimulationSession } from '@/modules/stimulationSession/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useStimulationSessionStore = createEntityStore<IStimulationSession>('stimulationSession')
