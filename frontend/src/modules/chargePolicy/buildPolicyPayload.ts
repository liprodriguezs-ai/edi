import { resolveAmountFlags } from '@/modules/chargePolicy/components/AmountModeSelector'
import {
  PERIODICITY,
  SURCHARGE_MODE,
  buildPolicyTargetSelector,
  type ChargePolicyFormValues,
  type SurchargeRuleFormValues,
} from '@/modules/chargePolicy/schema'
import { pesosToMinorUnits } from '@/shared/utils/money'

/** Porcentaje visible → basis points (5 → 500). */
function percentToBasisPoints(percent: number): number {
  return Math.round(percent * 100)
}

/**
 * Convierte las reglas de recargo del form (unidades visibles) al contrato backend:
 *  - PERCENT: surcharge_value en % → basis points.
 *  - FIXED: surcharge_value en pesos → minor units.
 *  - cap_amount: SIEMPRE pesos → minor units (o null).
 * Se incluye `id` solo cuando existe (sync de actualización del servicio).
 */
function buildSurchargeRulesPayload(
  rules: SurchargeRuleFormValues[] | undefined
): Record<string, unknown>[] {
  return (rules ?? []).map((rule) => {
    const isPercent = rule.surcharge_mode_id === SURCHARGE_MODE.PERCENT
    const value = rule.surcharge_value ?? 0
    return {
      ...(rule.id ? { id: rule.id } : {}),
      from_month: rule.from_month,
      surcharge_mode_id: rule.surcharge_mode_id,
      surcharge_value: isPercent ? percentToBasisPoints(value) : pesosToMinorUnits(value),
      recurrence: rule.recurrence,
      cap_amount: rule.cap_amount != null ? pesosToMinorUnits(rule.cap_amount) : null,
    }
  })
}

/**
 * Construye el payload de creación de política a partir de los valores del form.
 * Respeta el contrato backend (la regla se configura 1-a-1 con su lote inicial):
 *  - target_type + target_selector: SIEMPRE (requerido en ambos modos).
 *  - recurrente (no eventual): envía valid_from/valid_to; las fechas (issued_at/due_date)
 *    se omiten → el backend las deriva de valid_from.
 *  - eventual: envía issued_at/due_date (requeridas); NO envía vigencia (prohibida).
 * Los montos se convierten de pesos a minor units según el modo seleccionado.
 */
export function buildPolicyCreatePayload(values: ChargePolicyFormValues): Record<string, unknown> {
  const flags = resolveAmountFlags(values.amount_mode_id)
  const toMinor = (pesos: number | null) =>
    pesos != null && pesos > 0 ? pesosToMinorUnits(pesos) : null
  const isEventual = values.periodicity_id === PERIODICITY.EVENTUAL

  const payload: Record<string, unknown> = {
    charge_concept_id: values.charge_concept_id,
    name: values.name,
    amount_mode_id: values.amount_mode_id,
    fixed_amount: flags.isFixed ? toMinor(values.fixed_amount) : null,
    min_amount: flags.isRange ? toMinor(values.min_amount) : null,
    ideal_amount: flags.isRange ? toMinor(values.ideal_amount) : null,
    max_amount: flags.isRange ? toMinor(values.max_amount) : null,
    prorate_total_amount: flags.isByShare ? toMinor(values.prorate_total_amount) : null,
    grace_days: values.grace_days,
    // Override por regla de los días "por vencer"; null → usa el del clúster.
    due_soon_days: values.due_soon_days ?? null,
    periodicity_id: values.periodicity_id,
    is_active: values.is_active,
    // Alcance del lote inicial — requerido para AMBOS modos.
    target_type: values.target_type,
    target_selector: buildPolicyTargetSelector(values),
    // Calendario de recargos moratorios (puede ir vacío = sin recargos).
    surcharge_rules: buildSurchargeRulesPayload(values.surcharge_rules),
  }

  if (!isEventual) {
    payload.valid_from = values.valid_from
    payload.valid_to = values.valid_to
  } else {
    payload.issued_at = values.issued_at
    payload.due_date = values.due_date
  }

  return payload
}

/**
 * Payload de actualización (metadata): charge_concept_id es inmutable siempre (prohibido
 * por el request). `periodicity_id` y el alcance (`target_type`/`target_selector`, +
 * `issued_at`/`due_date` en eventual) solo son editables mientras `hasBilled = false` —
 * espeja el gate `prohibitedIf($hasBilled)` del backend (`ChargePolicyUpdateRequest`):
 * si se envían sin cambios estando facturada, el backend los rechaza con 422, así que se
 * OMITEN del payload (igual que `charge_concept_id` ya se omite siempre). La vigencia
 * (`valid_from`/`valid_to`, solo recurrente) y el monto SIEMPRE se envían, sin importar
 * `hasBilled`.
 */
export function buildPolicyUpdatePayload(
  values: ChargePolicyFormValues,
  hasBilled: boolean
): Record<string, unknown> {
  const flags = resolveAmountFlags(values.amount_mode_id)
  const toMinor = (pesos: number | null) =>
    pesos != null && pesos > 0 ? pesosToMinorUnits(pesos) : null
  const isEventual = values.periodicity_id === PERIODICITY.EVENTUAL

  const payload: Record<string, unknown> = {
    name: values.name,
    amount_mode_id: values.amount_mode_id,
    fixed_amount: flags.isFixed ? toMinor(values.fixed_amount) : null,
    min_amount: flags.isRange ? toMinor(values.min_amount) : null,
    ideal_amount: flags.isRange ? toMinor(values.ideal_amount) : null,
    max_amount: flags.isRange ? toMinor(values.max_amount) : null,
    prorate_total_amount: flags.isByShare ? toMinor(values.prorate_total_amount) : null,
    grace_days: values.grace_days,
    due_soon_days: values.due_soon_days ?? null,
    is_active: values.is_active,
    // El array REEMPLAZA las reglas de la política (el servicio sincroniza alta/edición/baja).
    surcharge_rules: buildSurchargeRulesPayload(values.surcharge_rules),
  }

  // Vigencia: siempre editable, sin importar hasBilled (sin efecto retroactivo sobre
  // charges ya generados — lo garantiza el backend).
  if (!isEventual) {
    payload.valid_from = values.valid_from
    payload.valid_to = values.valid_to
  }

  // Periodicidad + alcance (+ fechas del cobro eventual): SOLO antes de facturar.
  if (!hasBilled) {
    payload.periodicity_id = values.periodicity_id
    payload.target_type = values.target_type
    payload.target_selector = buildPolicyTargetSelector(values)
    if (isEventual) {
      payload.issued_at = values.issued_at
      payload.due_date = values.due_date
    }
  }

  return payload
}
