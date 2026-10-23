import type { IStimulationSession, StimulationSessionFormValues } from '@/modules/stimulationSession/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const StimulationSessionResource = createCrudApi<IStimulationSession, StimulationSessionFormValues>({
  basePath: '/stimulation_session',
})
