import type { IProjectContribution } from '@/modules/communityProject/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useProjectContributionStore = createEntityStore<IProjectContribution>('projectContribution')
