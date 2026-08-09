import type { IAmenitySlot } from '@/modules/amenity/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

/**
 * `amenity_slot` es un DETALLE de `amenity` (mismo `keyForPermission` en el
 * backend) — se gestiona en línea desde `AmenitySlotsEditor`, no tiene lista
 * propia. Se refetchea la amenidad (include `slots`) tras cada mutación.
 */
export const AmenitySlotResource = createCrudApi<IAmenitySlot, Record<string, unknown>>({
  basePath: '/amenity_slot',
})
