import type { IAmenity } from '@/modules/amenity/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useAmenityStore = createEntityStore<IAmenity>('amenity')
