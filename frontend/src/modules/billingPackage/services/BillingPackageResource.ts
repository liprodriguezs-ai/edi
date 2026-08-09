import type {
  BillingPackageFormValues,
  IBillingPackage,
  IPlanPriceMatrix,
  IPlanPriceMatrixModuleInput,
} from '@/modules/billingPackage/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

// ─── CRUD estándar de planes (Orion resource /billing_package) ───────────────

export const BillingPackageResource = createCrudApi<IBillingPackage, BillingPackageFormValues>({
  basePath: '/billing_package',
})

// ─── Editor de matriz de precios (rutas custom, NO Orion) ────────────────────

export const PlanPriceMatrixResource = {
  /**
   * GET /billing/plan/{planId}/price-matrix
   * Devuelve el plan, los intervalos disponibles y los módulos con sus celdas.
   */
  get: async (planId: string): Promise<IPlanPriceMatrix> => {
    const { data } = await apiClient.get<{ data: IPlanPriceMatrix }>(
      `/billing/plan/${planId}/price-matrix`
    )
    return data.data
  },

  /**
   * PUT /billing/plan/{planId}/price-matrix
   * Recibe un IPlanPriceMatrixModuleInput por módulo (flags + celdas en
   * centavos) y devuelve la matriz actualizada.
   */
  update: async (
    planId: string,
    modules: IPlanPriceMatrixModuleInput[]
  ): Promise<IPlanPriceMatrix> => {
    const { data } = await apiClient.put<{ data: IPlanPriceMatrix }>(
      `/billing/plan/${planId}/price-matrix`,
      { modules }
    )
    return data.data
  },
}
