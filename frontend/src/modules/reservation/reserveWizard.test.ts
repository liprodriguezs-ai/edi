import { RESERVATION_MODE } from '@/modules/amenity/schema'
import {
  buildReserveWizardSteps,
  canProceedFromDateTimeStep,
  canProceedFromRulesStep,
  buildReservationCreatePayload,
  costInputsFromAmenity,
  costInputsFromReservation,
  isBySlotAmenity,
  reserveDepositHint,
  resolvePostCreateOutcome,
  suggestedPaymentMinor,
} from '@/modules/reservation/reserveWizard'
import { RESERVATION_STATUS } from '@/modules/reservation/schema'
import { describe, expect, it } from 'vitest'

describe('isBySlotAmenity', () => {
  it('true only for BY_SLOT reservation_mode_id', () => {
    expect(isBySlotAmenity({ reservation_mode_id: RESERVATION_MODE.BY_SLOT })).toBe(true)
    expect(isBySlotAmenity({ reservation_mode_id: RESERVATION_MODE.BY_DAY })).toBe(false)
  })
})

describe('buildReserveWizardSteps', () => {
  it('adds "unit" only when the resident has more than one unit', () => {
    expect(buildReserveWizardSteps({ rules_text: null }, 1)).toEqual(['datetime', 'review'])
    expect(buildReserveWizardSteps({ rules_text: null }, 2)).toEqual(['unit', 'datetime', 'review'])
  })

  it('adds "rules" only when the amenity has rules_text', () => {
    expect(
      buildReserveWizardSteps({ rules_text: 'No corran en el área de la alberca' }, 1)
    ).toEqual(['datetime', 'rules', 'review'])
  })

  it('combines both unit and rules steps when applicable', () => {
    expect(buildReserveWizardSteps({ rules_text: 'Reglas' }, 3)).toEqual([
      'unit',
      'datetime',
      'rules',
      'review',
    ])
  })
})

describe('canProceedFromDateTimeStep', () => {
  const byDayAmenity = { reservation_mode_id: RESERVATION_MODE.BY_DAY }
  const bySlotAmenity = { reservation_mode_id: RESERVATION_MODE.BY_SLOT }

  it('requires a selected date for by_day amenities', () => {
    expect(canProceedFromDateTimeStep(byDayAmenity, null, null)).toBe(false)
    expect(canProceedFromDateTimeStep(byDayAmenity, '2026-08-01', null)).toBe(true)
  })

  it('requires BOTH date and slot for by_slot amenities', () => {
    expect(canProceedFromDateTimeStep(bySlotAmenity, '2026-08-01', null)).toBe(false)
    expect(canProceedFromDateTimeStep(bySlotAmenity, '2026-08-01', 'slot-1')).toBe(true)
    expect(canProceedFromDateTimeStep(bySlotAmenity, null, 'slot-1')).toBe(false)
  })
})

describe('canProceedFromRulesStep', () => {
  it('only advances once the resident marks the checkbox', () => {
    expect(canProceedFromRulesStep(false)).toBe(false)
    expect(canProceedFromRulesStep(true)).toBe(true)
  })
})

describe('reserveDepositHint', () => {
  it('returns null when there is no cost', () => {
    expect(reserveDepositHint({ costAmountMinor: null, depositAmountMinor: null })).toBeNull()
  })

  it('minimum = full cost when there is no deposit', () => {
    expect(reserveDepositHint({ costAmountMinor: 50000, depositAmountMinor: null })).toEqual({
      minimumMinor: 50000,
      totalMinor: 50000,
      isPartial: false,
    })
  })

  it('minimum = deposit and isPartial=true when a deposit is required and lower than the total', () => {
    expect(reserveDepositHint({ costAmountMinor: 50000, depositAmountMinor: 20000 })).toEqual({
      minimumMinor: 20000,
      totalMinor: 50000,
      isPartial: true,
    })
  })
})

describe('suggestedPaymentMinor', () => {
  it('suggests the deposit on a first payment (nothing settled yet)', () => {
    expect(
      suggestedPaymentMinor({ costAmountMinor: 50000, depositAmountMinor: 20000 }, 50000)
    ).toBe(20000)
  })

  it('suggests the full outstanding on a second/balance payment (deposit already applied)', () => {
    // BUG FIX (Slice E): outstandingMinor (30000) no longer equals totalMinor
    // (50000) once the deposit already applied — the sheet must default to
    // the remaining balance, not offer the deposit amount again.
    expect(
      suggestedPaymentMinor({ costAmountMinor: 50000, depositAmountMinor: 20000 }, 30000)
    ).toBe(30000)
  })

  it('suggests the full outstanding when there is no deposit at all', () => {
    expect(suggestedPaymentMinor({ costAmountMinor: 50000, depositAmountMinor: null }, 50000)).toBe(
      50000
    )
  })
})

describe('costInputsFromAmenity / costInputsFromReservation', () => {
  it('reads has_cost/requires_deposit from the amenity (pre-creation)', () => {
    expect(
      costInputsFromAmenity({
        has_cost: true,
        cost_amount: 30000,
        requires_deposit: true,
        deposit_amount: 10000,
      })
    ).toEqual({ costAmountMinor: 30000, depositAmountMinor: 10000 })

    expect(
      costInputsFromAmenity({
        has_cost: false,
        cost_amount: null,
        requires_deposit: false,
        deposit_amount: null,
      })
    ).toEqual({ costAmountMinor: null, depositAmountMinor: null })
  })

  it('reads the FROZEN snapshot from an existing reservation, not the amenity config', () => {
    expect(costInputsFromReservation({ cost_amount: 30000, deposit_amount: null })).toEqual({
      costAmountMinor: 30000,
      depositAmountMinor: null,
    })
    expect(costInputsFromReservation({ cost_amount: null, deposit_amount: null })).toEqual({
      costAmountMinor: null,
      depositAmountMinor: null,
    })
  })
})

describe('resolvePostCreateOutcome', () => {
  it('chains into the payment sheet only when the created reservation is PENDING_PAYMENT', () => {
    expect(resolvePostCreateOutcome(RESERVATION_STATUS.PENDING_PAYMENT)).toBe('payment')
  })

  it('shows an approval-pending notice — never the payment sheet — for PENDING_APPROVAL', () => {
    // BUG regression (E2E QA round 2): a costed amenity that ALSO requires
    // approval creates the reservation as PENDING_APPROVAL, which the backend
    // rejects for payment (422). Branching on amenity.has_cost instead of the
    // real status was exactly this bug.
    expect(resolvePostCreateOutcome(RESERVATION_STATUS.PENDING_APPROVAL)).toBe('approval_pending')
  })

  it('treats any other status (e.g. CONFIRMED — no cost, no approval) as already resolved', () => {
    expect(resolvePostCreateOutcome(RESERVATION_STATUS.CONFIRMED)).toBe('confirmed')
  })
})

describe('buildReservationCreatePayload', () => {
  it('omits slot_id/rules_accepted when not provided', () => {
    expect(
      buildReservationCreatePayload({
        amenityId: 'amenity-1',
        unitId: 'unit-1',
        reservationDate: '2026-08-01',
        slotId: null,
        rulesAccepted: false,
      })
    ).toEqual({
      amenity_id: 'amenity-1',
      unit_id: 'unit-1',
      reservation_date: '2026-08-01',
    })
  })

  it('includes slot_id and rules_accepted when provided', () => {
    expect(
      buildReservationCreatePayload({
        amenityId: 'amenity-1',
        unitId: 'unit-1',
        reservationDate: '2026-08-01',
        slotId: 'slot-1',
        rulesAccepted: true,
      })
    ).toEqual({
      amenity_id: 'amenity-1',
      unit_id: 'unit-1',
      reservation_date: '2026-08-01',
      slot_id: 'slot-1',
      rules_accepted: true,
    })
  })
})
