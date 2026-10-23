import type { IStimulationGroup, StimulationGroupFormValues } from '@/modules/stimulationGroup/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const StimulationGroupResource = createCrudApi<IStimulationGroup, StimulationGroupFormValues>({
  basePath: '/stimulation_group',
})
