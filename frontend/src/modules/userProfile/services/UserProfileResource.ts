import type { IUserProfile, UserProfileFormValues } from '@/modules/userProfile/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const UserProfileResource = createCrudApi<IUserProfile, UserProfileFormValues>({
  basePath: '/user_profile',
})
