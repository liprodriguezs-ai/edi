import type { IAmenityReservation } from '@/modules/reservation/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useAmenityReservationStore =
  createEntityStore<IAmenityReservation>('amenityReservation')
