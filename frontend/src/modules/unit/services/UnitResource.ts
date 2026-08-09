import type { IUnit, IUnitUser, UnitFormValues } from '@/modules/unit/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

interface ChangeOwnerPayload {
  user_id: string
  change_date?: string
  notes?: string
}

export const UnitResource = createCrudApi<IUnit, UnitFormValues, Record<string, unknown>, {
  my: () => Promise<IUnit[]>
  changeOwner: (_id: string, _payload: ChangeOwnerPayload) => Promise<IUnitUser>
}>({
  basePath: '/unit',
  customMethods: (basePath) => ({
    my: async () => {
      const { data } = await apiClient.get<{ data: IUnit[] }>(`${basePath}/my`)
      return data.data
    },
    changeOwner: async (id: string, payload: ChangeOwnerPayload) => {
      const { data } = await apiClient.post<{ data: IUnitUser }>(`${basePath}/${id}/change-owner`, payload)
      return data.data
    },
  }),
})
