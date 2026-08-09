import type { IVehicle } from '@/modules/vehicle/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useVehicleStore = createEntityStore<IVehicle>('vehicle')
