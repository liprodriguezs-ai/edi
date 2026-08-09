import type { IUser } from '@/modules/user/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUserStore = createEntityStore<IUser>('user');
