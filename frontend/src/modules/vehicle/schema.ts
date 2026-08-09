import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const vehicleFormSchema = z.object({
  unit_id: z.string().min(1, 'La unidad es requerida'),
  plate: z.string().min(1, 'La placa es requerida').max(20),
  make: z.string().max(50).optional(),
  model: z.string().max(50).optional(),
  color: z.string().max(30).optional(),
  year: z.number().int().min(1950).max(2100).nullable(),
})

export interface IVehicle extends IEntity {
  unit_id: string
  plate: string
  make?: string | null
  model?: string | null
  color?: string | null
  year?: number | null
  unit?: { id: string; full_identifier?: string; number?: string | null } | null
}

export type VehicleFormValues = z.infer<typeof vehicleFormSchema>
