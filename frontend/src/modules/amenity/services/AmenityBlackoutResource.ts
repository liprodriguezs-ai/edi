import type { IAmenityBlackout } from '@/modules/amenity/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

/**
 * `amenity_blackout` es un DETALLE de `amenity` (mismo `keyForPermission` en el
 * backend) — se gestiona en línea desde `AmenityBlackoutsEditor`, no tiene
 * lista propia. Se refetchea la amenidad (include `blackouts`) tras cada mutación.
 */
export const AmenityBlackoutResource = createCrudApi<IAmenityBlackout, Record<string, unknown>>({
  basePath: '/amenity_blackout',
})
