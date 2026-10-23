import type { IBankArea, IBankItem } from '@/modules/screening/schema'

export type AreaCaptureStatus = 'pending' | 'needs_alternate' | 'complete'

export interface AreaProgress {
  answered: number
  total: number
  requiredAlternates: number
  answeredAlternates: number
  /** Unanswered items, own first, then required alternates. */
  missingItemIds: string[]
  status: AreaCaptureStatus
}

/**
 * Capture progress of one area: its own items plus the previous group's
 * items the server currently requires for it. The single source for the
 * area header, the section chips and the review step, so they never disagree.
 * Locked items (derived by the server) count as answered.
 */
export function areaProgress(
  area: IBankArea,
  answers: Record<string, string>,
  alternateItems: IBankItem[] | undefined
): AreaProgress {
  const isAnswered = (item: IBankItem) => item.locked || answers[item.screening_item_id] !== undefined
  const ownItems = area.items.filter((item) => !item.is_alternate)
  const alternates = alternateItems ?? []
  const items = [...ownItems, ...alternates]

  const answeredAlternates = alternates.filter(isAnswered).length
  const missingItemIds = items.filter((item) => !isAnswered(item)).map((item) => item.screening_item_id)
  const answered = items.length - missingItemIds.length

  let status: AreaCaptureStatus = 'pending'
  if (answeredAlternates < alternates.length) status = 'needs_alternate'
  else if (missingItemIds.length === 0 && items.length > 0) status = 'complete'

  return {
    answered,
    total: items.length,
    requiredAlternates: alternates.length,
    answeredAlternates,
    missingItemIds,
    status,
  }
}
