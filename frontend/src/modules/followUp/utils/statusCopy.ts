import type { IFollowUp } from '@/modules/followUp/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'

/** Spanish label fallback + pill tone for a follow_up_status key. */
export function followUpStatusTone(key?: string | null): PillTone {
  switch (key) {
    case 'completed':
      return 'g'
    case 'in_progress':
      return 'b'
    case 'overdue':
      return 'r'
    case 'cancelled':
      return 'r'
    case 'pending':
    default:
      return 'y'
  }
}

export function followUpTypeLabel(key?: string | null): string {
  switch (key) {
    case 'reassess_3_months':
      return 'Reaplicación a 3 meses'
    case 'specialist_referral':
      return 'Referencia a especialista'
    case 'confirmatory_test':
      return 'Prueba confirmatoria'
    default:
      return '—'
  }
}

/** Follow-up statuses that still ask for work. */
export const OPEN_FOLLOW_UP_STATUSES: readonly string[] = ['overdue', 'pending', 'in_progress']

export function isOpenFollowUp(followUp: IFollowUp): boolean {
  return OPEN_FOLLOW_UP_STATUSES.includes(followUp.follow_up_status?.key ?? '')
}
