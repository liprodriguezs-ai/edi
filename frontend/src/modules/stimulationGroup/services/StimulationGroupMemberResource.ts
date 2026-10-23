import type { IStimulationGroupMember, MemberFormValues } from '@/modules/stimulationGroup/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const StimulationGroupMemberResource = createCrudApi<IStimulationGroupMember, MemberFormValues>({
  basePath: '/stimulation_group_member',
})
