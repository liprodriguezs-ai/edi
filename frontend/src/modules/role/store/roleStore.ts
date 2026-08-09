import type { IRole } from '@/modules/role/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useRoleStore = createEntityStore<IRole>('role')
