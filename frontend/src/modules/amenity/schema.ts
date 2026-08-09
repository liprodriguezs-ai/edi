import type { SingleTargetNames } from '@/shared/components/target/SingleTargetSelector'
import type { IEntity, IMedia } from '@/shared/interfaces/Entity'
import { selectorIds, selectorString } from '@/shared/utils/targetSelector'
import type { IMoney } from '@/shared/utils/money'
import { moneyToMinorUnits, minorUnitsToPesos } from '@/shared/utils/money'
import { z } from 'zod'

/**
 * Catálogos CENTRALES (UUIDs fijos, sembrados por CatalogSeeder) del módulo de
 * amenidades. Espejan `ReservationMode` en el backend.
 */
export const RESERVATION_MODE = {
  BY_DAY: 'e7d9abab-af97-4753-a87d-bcebf5026af7',
  BY_SLOT: '70dddcdd-542b-4595-ad66-1e34e27df7e4',
} as const

interface INamedCatalog {
  id: string
  name: string
}

/** 0=domingo..6=sábado — espeja `available_weekdays`/`amenity_slot.weekday` del backend. */
export const WEEKDAY_LABELS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] as const

export interface IAmenitySlot extends IEntity {
  amenity_id: string
  weekday: number
  start_time: string
  end_time: string
  capacity_override?: number | null
}

export interface IAmenityBlackout extends IEntity {
  amenity_id: string
  start_date: string
  end_date: string
  reason?: string | null
}

export interface IAmenity extends IEntity {
  name: string
  description?: string
  restrictions?: string | null
  capacity: number
  /** JSON array de weekdays (0=domingo..6=sábado) en los que se puede reservar. */
  available_weekdays: number[]
  rules_text?: string | null
  out_of_service?: boolean
  out_of_service_reason?: string | null
  has_cost?: boolean
  cost_amount?: IMoney | number | null
  requires_deposit?: boolean
  deposit_amount?: IMoney | number | null
  deposit_due_days?: number | null
  bank_account_id?: string | null
  requires_approval?: boolean
  reservation_mode_id: string
  reservation_mode?: INamedCatalog | null
  bank_account?: INamedCatalog | null
  slots?: IAmenitySlot[]
  blackouts?: IAmenityBlackout[]
  media?: IMedia[]
  /** Alcance de lectura/reserva (amenity-visibility-target). `null`/ausente = `'all'`. */
  target_type?: string | null
  target_selector?: Record<string, unknown> | null
}

/**
 * Nombres RHF del alcance de la amenidad (¿quién puede ver y reservar?). Espeja el bloque de
 * LECTURA de Chat (`target_unit_feature_ids`, `target_floors` plural) — NO el default de
 * `SingleTargetSelector`, que es el de cobranza (singular).
 */
export const AMENITY_TARGET_NAMES = {
  targetType: 'target_type',
  unitTypeIds: 'target_unit_type_ids',
  towerIds: 'target_tower_ids',
  sectionIds: 'target_section_ids',
  featureIds: 'target_unit_feature_ids',
  floor: 'target_floors',
  unitId: 'target_unit_id',
  unitIds: 'target_unit_ids',
} as const satisfies SingleTargetNames

/** Montos del form en PESOS; se convierten a minor units al enviar (ver buildAmenityPayload). */
export const amenityFormSchema = z
  .object({
    name: z.string().min(1, 'El nombre es requerido').max(255),
    description: z.string().max(2000).optional(),
    restrictions: z.string().max(2000).optional(),
    capacity: z.number().int().min(1, 'La capacidad debe ser mayor a 0'),
    available_weekdays: z
      .array(z.number().int().min(0).max(6))
      .min(1, 'Selecciona al menos un día'),
    rules_text: z.string().optional(),
    out_of_service: z.boolean(),
    out_of_service_reason: z.string().max(255).optional(),
    has_cost: z.boolean(),
    cost_amount: z.number().min(0).nullable(),
    requires_deposit: z.boolean(),
    deposit_amount: z.number().min(0).nullable(),
    deposit_due_days: z.number().int().min(0).max(365).nullable(),
    bank_account_id: z.string().optional(),
    requires_approval: z.boolean(),
    reservation_mode_id: z.string().min(1, 'El modo de reservación es requerido'),
    // Alcance (¿quién puede ver y reservar?) — mismo vocabulario que TargetResolver.
    // `by_type`/`by_tower`/`by_section`/`by_feature` son MULTISELECT; `by_floor`/`single_unit`
    // quedan vivos solo para datos legacy (SingleTargetSelector no los ofrece por defecto).
    target_type: z.string().min(1, 'El alcance es requerido'),
    target_unit_type_ids: z.array(z.string()).optional(),
    target_tower_ids: z.array(z.string()).optional(),
    target_section_ids: z.array(z.string()).optional(),
    target_floors: z.array(z.string()).optional(),
    target_unit_feature_ids: z.array(z.string()).optional(),
    target_unit_id: z.string().optional(),
    target_unit_ids: z.array(z.string()).optional(),
  })
  .superRefine((values, ctx) => {
    const need = (cond: boolean, path: string, message: string) => {
      if (cond) ctx.addIssue({ code: 'custom', path: [path], message })
    }
    switch (values.target_type) {
      case 'by_type':
        need(
          !values.target_unit_type_ids?.length,
          'target_unit_type_ids',
          'Selecciona al menos un tipo de unidad'
        )
        break
      case 'by_tower':
        need(!values.target_tower_ids?.length, 'target_tower_ids', 'Selecciona al menos una torre')
        break
      case 'by_section':
        need(
          !values.target_section_ids?.length,
          'target_section_ids',
          'Selecciona al menos una sección'
        )
        break
      case 'by_floor':
        need(!values.target_tower_ids?.length, 'target_tower_ids', 'Selecciona al menos una torre')
        need(!values.target_floors?.length, 'target_floors', 'Ingresa al menos un piso')
        break
      case 'by_feature':
        need(
          !values.target_unit_feature_ids?.length,
          'target_unit_feature_ids',
          'Selecciona al menos una característica'
        )
        break
      case 'single_unit':
        need(!values.target_unit_id, 'target_unit_id', 'Selecciona la unidad')
        break
      case 'manual':
        need(!values.target_unit_ids?.length, 'target_unit_ids', 'Selecciona al menos una unidad')
        break
    }
    if (values.has_cost) {
      if (values.cost_amount == null || values.cost_amount <= 0) {
        ctx.addIssue({
          code: 'custom',
          path: ['cost_amount'],
          message: 'El monto del costo es requerido',
        })
      }
      if (!values.bank_account_id) {
        ctx.addIssue({
          code: 'custom',
          path: ['bank_account_id'],
          message: 'La cuenta destino es requerida cuando la amenidad tiene costo',
        })
      }
    }
    if (values.requires_deposit) {
      if (values.deposit_amount == null || values.deposit_amount <= 0) {
        ctx.addIssue({
          code: 'custom',
          path: ['deposit_amount'],
          message: 'El monto del depósito es requerido',
        })
      }
      if (values.deposit_due_days == null) {
        ctx.addIssue({
          code: 'custom',
          path: ['deposit_due_days'],
          message: 'Los días para liquidar el depósito son requeridos',
        })
      }
    }
  })

export type AmenityFormValues = z.infer<typeof amenityFormSchema>

/**
 * Valores por defecto del formulario a partir de una amenidad existente (edición,
 * montos convertidos de minor units → pesos) o en blanco (alta).
 */
export function buildAmenityFormDefaults(amenity?: IAmenity | null): AmenityFormValues {
  return {
    name: amenity?.name ?? '',
    description: amenity?.description ?? '',
    restrictions: amenity?.restrictions ?? '',
    capacity: amenity?.capacity ?? 1,
    available_weekdays: amenity?.available_weekdays ?? [],
    rules_text: amenity?.rules_text ?? '',
    out_of_service: amenity?.out_of_service ?? false,
    out_of_service_reason: amenity?.out_of_service_reason ?? '',
    has_cost: amenity?.has_cost ?? false,
    cost_amount:
      amenity?.cost_amount != null
        ? minorUnitsToPesos(moneyToMinorUnits(amenity.cost_amount))
        : null,
    requires_deposit: amenity?.requires_deposit ?? false,
    deposit_amount:
      amenity?.deposit_amount != null
        ? minorUnitsToPesos(moneyToMinorUnits(amenity.deposit_amount))
        : null,
    deposit_due_days: amenity?.deposit_due_days ?? null,
    bank_account_id: amenity?.bank_account_id ?? '',
    requires_approval: amenity?.requires_approval ?? false,
    reservation_mode_id: amenity?.reservation_mode_id ?? '',
    target_type: amenity?.target_type ?? 'all',
    target_unit_type_ids: selectorIds(amenity?.target_selector, 'unit_type_ids'),
    target_tower_ids: selectorIds(amenity?.target_selector, 'tower_ids'),
    target_section_ids: selectorIds(amenity?.target_selector, 'section_ids'),
    target_floors: selectorIds(amenity?.target_selector, 'floors'),
    target_unit_feature_ids: selectorIds(amenity?.target_selector, 'unit_feature_ids'),
    target_unit_id: selectorString(amenity?.target_selector, 'unit_id'),
    target_unit_ids: selectorIds(amenity?.target_selector, 'unit_ids'),
  }
}

/** Alta/edición de un horario fijo (`amenity_slot`, solo modo `by_slot`). Horas en HH:mm. */
export const amenitySlotFormSchema = z
  .object({
    weekday: z.number().int().min(0).max(6),
    start_time: z.string().min(1, 'La hora de inicio es requerida'),
    end_time: z.string().min(1, 'La hora de fin es requerida'),
    capacity_override: z.number().int().min(1).nullable(),
  })
  .superRefine((values, ctx) => {
    if (values.start_time && values.end_time && values.end_time <= values.start_time) {
      ctx.addIssue({
        code: 'custom',
        path: ['end_time'],
        message: 'La hora de fin debe ser posterior a la de inicio',
      })
    }
  })

export type AmenitySlotFormValues = z.infer<typeof amenitySlotFormSchema>

/** Alta de un bloqueo de fechas (`amenity_blackout`). */
export const amenityBlackoutFormSchema = z
  .object({
    start_date: z.string().min(1, 'La fecha de inicio es requerida'),
    end_date: z.string().min(1, 'La fecha de fin es requerida'),
    reason: z.string().max(1000).optional(),
  })
  .superRefine((values, ctx) => {
    if (values.start_date && values.end_date && values.end_date < values.start_date) {
      ctx.addIssue({
        code: 'custom',
        path: ['end_date'],
        message: 'La fecha de fin debe ser posterior o igual a la de inicio',
      })
    }
  })

export type AmenityBlackoutFormValues = z.infer<typeof amenityBlackoutFormSchema>

/**
 * S14 — `GET /amenity/{id}/availability`. Un "día" trae siempre el veredicto
 * estructural (`open`/`reason`) + `available` (estructural AND ocupación); en
 * modo `by_slot` además trae la lista de horarios con su cupo (`slots`).
 */
export interface IAmenityAvailabilitySlot {
  slot_id: string
  weekday: number
  start_time: string
  end_time: string
  start_at: string
  end_at: string
  capacity: number
  taken: number
  free: number
}

/** `reason` cuando `open` es `false` — espeja `AmenityAvailabilityService::structuralAvailability`. */
export type AmenityAvailabilityReason = 'out_of_service' | 'closed_weekday' | 'blackout'

export interface IAmenityAvailabilityDay {
  date: string
  open: boolean
  reason: AmenityAvailabilityReason | null
  available: boolean
  slots?: IAmenityAvailabilitySlot[]
}

export interface IAmenityAvailabilityResponse {
  amenity_id: string
  mode: 'by_day' | 'by_slot'
  timezone: string
  days: IAmenityAvailabilityDay[]
}
