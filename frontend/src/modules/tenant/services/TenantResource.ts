import type { IProvisionTenantRequest, ITenant, TenantFormValues } from '@/modules/tenant/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

/**
 * `provision` es el alta de super-admin con facturación integrada:
 * POST /tenant/provision → crea la organización y provisiona su billing (trial /
 * cortesía / pago manual / tarjeta) en un solo paso. Devuelve el tenant creado
 * (desenvuelto de `data.tenant`). El monto SIEMPRE lo calcula el backend.
 */
export const TenantResource = createCrudApi<
  ITenant,
  TenantFormValues,
  Record<string, unknown>,
  {
    provision: (_payload: IProvisionTenantRequest) => Promise<ITenant>
  }
>({
  basePath: '/tenant',
  customMethods: (basePath) => ({
    provision: async (payload: IProvisionTenantRequest) => {
      const { data } = await apiClient.post<{ data: { tenant: ITenant } }>(
        `${basePath}/provision`,
        payload,
      )
      return data.data.tenant
    },
  }),
})
