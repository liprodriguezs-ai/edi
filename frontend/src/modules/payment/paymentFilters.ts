import { resolveDateRange, type DateRange } from '@/shared/components/filters/DateRangeFilter'
import type { Filter } from '@/shared/interfaces/list.types'
import { pesosToMinorUnits } from '@/shared/utils/money'

// ─── Filter state types ─────────────────────────────────────────────────────

/** Amount fields are captured in PESOS (display units) and converted to minor units on search. */
export interface PaymentFilters {
  totalAmountMinPesos: number | null
  totalAmountMaxPesos: number | null
  paidAt: DateRange
  statusIds: string[]
  methodIds: string[]
  unitIds: string[]
  bankAccountIds: string[]
}

const EMPTY_DATE_RANGE: DateRange = { from: null, to: null, shortcut: null }

export const INITIAL_PAYMENT_FILTERS: PaymentFilters = {
  totalAmountMinPesos: null,
  totalAmountMaxPesos: null,
  paidAt: EMPTY_DATE_RANGE,
  statusIds: [],
  methodIds: [],
  unitIds: [],
  bankAccountIds: [],
}

/**
 * Builds the Orion-compatible filter array from the local PaymentFilters state.
 * Amounts entered by the user are in pesos and converted to minor units.
 * All clauses combine with AND semantics (Orion default).
 */
export function buildPaymentFilters(filters: PaymentFilters): Filter[] {
  const result: Filter[] = []

  if (filters.totalAmountMinPesos !== null) {
    result.push({ field: 'total_amount', operator: '>=', value: pesosToMinorUnits(filters.totalAmountMinPesos) })
  }
  if (filters.totalAmountMaxPesos !== null) {
    result.push({ field: 'total_amount', operator: '<=', value: pesosToMinorUnits(filters.totalAmountMaxPesos) })
  }

  const paidResolved = resolveDateRange(filters.paidAt)
  if (paidResolved.from) result.push({ field: 'paid_at', operator: '>=', value: paidResolved.from })
  if (paidResolved.to) result.push({ field: 'paid_at', operator: '<=', value: paidResolved.to })

  // Estado: payment.payment_status_id es FK directa (filterableBy del controller).
  if (filters.statusIds.length > 0) {
    result.push({ field: 'payment_status_id', operator: 'in', value: filters.statusIds })
  }

  // Método: payment.payment_method_id es FK directa (filterableBy del controller).
  if (filters.methodIds.length > 0) {
    result.push({ field: 'payment_method_id', operator: 'in', value: filters.methodIds })
  }

  if (filters.unitIds.length > 0) {
    result.push({ field: 'unit_id', operator: 'in', value: filters.unitIds })
  }

  if (filters.bankAccountIds.length > 0) {
    result.push({ field: 'bank_account_id', operator: 'in', value: filters.bankAccountIds })
  }

  return result
}
