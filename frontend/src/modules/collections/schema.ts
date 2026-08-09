import type { IMoney } from '@/shared/utils/money'

/**
 * Per-unit delinquency state (design §6.2, R-DELQ-1) — shared domain type
 * consumed across modules (`reservation`, `access`) via `useMyRestrictions`.
 * Gating on this MUST stay per unit; never aggregate multiple entries into a
 * single "is this resident restricted" boolean.
 */
export interface DelinquencyUnitState {
  unit_id: string
  restricted: boolean
  features: {
    amenities: boolean
    visitor_access: boolean
  }
  overdue_balance?: IMoney
}
