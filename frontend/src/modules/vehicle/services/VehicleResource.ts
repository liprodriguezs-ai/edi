import type { IVehicle, VehicleFormValues } from '@/modules/vehicle/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const VehicleResource = createCrudApi<IVehicle, VehicleFormValues>({
  basePath: '/vehicle',
})
