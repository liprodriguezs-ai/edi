import type { AmenityFormValues } from '@/modules/amenity/schema'
import { pesosToMinorUnits } from '@/shared/utils/money'
import { buildTargetSelector } from '@/shared/utils/targetSelector'

/**
 * Construye el payload que consume `AmenityCreateRequest`/`AmenityUpdateRequest`.
 * Los montos viajan del form en PESOS y se convierten a minor units; los campos
 * dependientes de un toggle apagado se envían en `null` (el backend los ignora,
 * pero mantiene el estado limpio si el admin desactiva costo/depósito).
 */
export function buildAmenityPayload(values: AmenityFormValues): Record<string, unknown> {
  return {
    name: values.name,
    description: values.description || null,
    restrictions: values.restrictions || null,
    capacity: values.capacity,
    available_weekdays: values.available_weekdays,
    rules_text: values.rules_text || null,
    out_of_service: values.out_of_service,
    out_of_service_reason: values.out_of_service ? values.out_of_service_reason || null : null,
    has_cost: values.has_cost,
    cost_amount:
      values.has_cost && values.cost_amount != null ? pesosToMinorUnits(values.cost_amount) : null,
    requires_deposit: values.requires_deposit,
    deposit_amount:
      values.requires_deposit && values.deposit_amount != null
        ? pesosToMinorUnits(values.deposit_amount)
        : null,
    deposit_due_days: values.requires_deposit ? values.deposit_due_days : null,
    bank_account_id: values.has_cost ? values.bank_account_id || null : null,
    requires_approval: values.requires_approval,
    reservation_mode_id: values.reservation_mode_id,
    target_type: values.target_type,
    target_selector: buildTargetSelector(
      values.target_type,
      {
        unitType: values.target_unit_type_ids,
        tower: values.target_tower_ids,
        section: values.target_section_ids,
        floor: values.target_floors,
        feature: values.target_unit_feature_ids,
        unitId: values.target_unit_id ?? '',
        unitIds: values.target_unit_ids,
      },
      { floorIncludesTowers: true }
    ),
  }
}
