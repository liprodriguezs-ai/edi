import {
  RESERVATION_STATUS,
  displayableOutstandingAmount,
  isTerminalReservationStatus,
  reservationStatusTone,
  surchargeFormSchema,
} from '@/modules/reservation/schema'
import { describe, expect, it } from 'vitest'

describe('surchargeFormSchema', () => {
  it('accepts a valid surcharge (concept + positive amount)', () => {
    const result = surchargeFormSchema.safeParse({
      charge_concept_id: 'concept-1',
      amount: 500,
      description: 'Vidrio roto en la alberca',
    })
    expect(result.success).toBe(true)
  })

  it('accepts a valid surcharge without description (optional)', () => {
    const result = surchargeFormSchema.safeParse({ charge_concept_id: 'concept-1', amount: 250 })
    expect(result.success).toBe(true)
  })

  it('requires charge_concept_id', () => {
    const result = surchargeFormSchema.safeParse({ charge_concept_id: '', amount: 100 })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('charge_concept_id'))).toBe(true)
    }
  })

  it('rejects a zero or negative amount', () => {
    const result = surchargeFormSchema.safeParse({ charge_concept_id: 'concept-1', amount: 0 })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('amount'))).toBe(true)
    }
  })

  it('rejects a missing amount', () => {
    const result = surchargeFormSchema.safeParse({ charge_concept_id: 'concept-1' })
    expect(result.success).toBe(false)
  })
})

describe('reservationStatusTone', () => {
  it('returns green for confirmed and completed', () => {
    expect(reservationStatusTone(RESERVATION_STATUS.CONFIRMED)).toBe('g')
    expect(reservationStatusTone(RESERVATION_STATUS.COMPLETED)).toBe('g')
  })

  it('returns yellow for pending states', () => {
    expect(reservationStatusTone(RESERVATION_STATUS.PENDING_APPROVAL)).toBe('y')
    expect(reservationStatusTone(RESERVATION_STATUS.PENDING_PAYMENT)).toBe('y')
  })

  it('returns red for cancelled, rejected, and no-show', () => {
    expect(reservationStatusTone(RESERVATION_STATUS.CANCELLED)).toBe('r')
    expect(reservationStatusTone(RESERVATION_STATUS.REJECTED)).toBe('r')
    expect(reservationStatusTone(RESERVATION_STATUS.NO_SHOW)).toBe('r')
  })

  it('falls back to red for an unknown/missing status', () => {
    expect(reservationStatusTone(undefined)).toBe('r')
    expect(reservationStatusTone('unknown-id')).toBe('r')
  })
})

describe('isTerminalReservationStatus', () => {
  it('is true for cancelled/rejected/completed/no-show', () => {
    expect(isTerminalReservationStatus(RESERVATION_STATUS.CANCELLED)).toBe(true)
    expect(isTerminalReservationStatus(RESERVATION_STATUS.REJECTED)).toBe(true)
    expect(isTerminalReservationStatus(RESERVATION_STATUS.COMPLETED)).toBe(true)
    expect(isTerminalReservationStatus(RESERVATION_STATUS.NO_SHOW)).toBe(true)
  })

  it('is false for still-alive statuses and for missing status', () => {
    expect(isTerminalReservationStatus(RESERVATION_STATUS.PENDING_APPROVAL)).toBe(false)
    expect(isTerminalReservationStatus(RESERVATION_STATUS.PENDING_PAYMENT)).toBe(false)
    expect(isTerminalReservationStatus(RESERVATION_STATUS.CONFIRMED)).toBe(false)
    expect(isTerminalReservationStatus(undefined)).toBe(false)
    expect(isTerminalReservationStatus(null)).toBe(false)
  })
})

describe('displayableOutstandingAmount', () => {
  it('shows $0 for a terminal status regardless of the stored amount_outstanding', () => {
    // BUG regression (E2E QA round 2, residual from round 1): amount_outstanding
    // can stay frozen at a non-zero value after cancel/reject (see
    // ReservationService::cancel()/expire()) — displaying it confuses users
    // about a dead reservation's balance.
    expect(displayableOutstandingAmount(RESERVATION_STATUS.CANCELLED, 50000)).toBe(0)
    expect(displayableOutstandingAmount(RESERVATION_STATUS.REJECTED, 50000)).toBe(0)
    expect(displayableOutstandingAmount(RESERVATION_STATUS.COMPLETED, 50000)).toBe(0)
    expect(displayableOutstandingAmount(RESERVATION_STATUS.NO_SHOW, 50000)).toBe(0)
  })

  it('passes the real amount through for a still-alive status', () => {
    expect(displayableOutstandingAmount(RESERVATION_STATUS.PENDING_PAYMENT, 50000)).toBe(50000)
    expect(displayableOutstandingAmount(RESERVATION_STATUS.CONFIRMED, 50000)).toBe(50000)
  })

  it('defaults to 0 when the amount is null/undefined', () => {
    expect(displayableOutstandingAmount(RESERVATION_STATUS.CONFIRMED, null)).toBe(0)
    expect(displayableOutstandingAmount(RESERVATION_STATUS.CONFIRMED, undefined)).toBe(0)
  })
})
