import type { ICollaborator } from '@/modules/collaborator/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useCollaboratorStore = createEntityStore<ICollaborator>('collaborator')
