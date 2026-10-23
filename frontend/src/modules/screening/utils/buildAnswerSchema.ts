import type { IBankItem } from '@/modules/screening/schema'
import { z } from 'zod'

/**
 * REQ-SUI-011/D4 — the item set varies per group/version, so the answer
 * schema is built AT RUNTIME from the fetched bank: one `z.enum` of that
 * item's own option ids per item. Used for per-answer validation (rejects a
 * foreign option id) and for local completeness/progress — never as the
 * source of truth (the server is always the real gate, REQ-SUI-006).
 */
export function buildAnswerSchema(items: IBankItem[]): z.ZodObject<Record<string, z.ZodTypeAny>> {
  const shape: Record<string, z.ZodTypeAny> = {}

  for (const item of items) {
    const optionIds = item.options.map((option) => option.screening_item_option_id)
    if (optionIds.length === 0) continue
    shape[item.screening_item_id] = z.enum(optionIds as [string, ...string[]])
  }

  return z.object(shape).partial()
}

/** Validates ONE answer against the item it belongs to. */
export function isValidAnswerForItem(item: IBankItem, screeningItemOptionId: string): boolean {
  return item.options.some((option) => option.screening_item_option_id === screeningItemOptionId)
}

/**
 * Items that count toward local completeness/progress. Locked items are
 * excluded (D4): the server derives their answer, the client never requires
 * one to enable the score button.
 */
export function requiredItemIds(items: IBankItem[]): string[] {
  return items.filter((item) => !item.locked).map((item) => item.screening_item_id)
}

/** Every area's own+alternate items flattened, for progress/schema building. */
export function flattenBankItems(areas: { items: IBankItem[] }[]): IBankItem[] {
  return areas.flatMap((area) => area.items)
}
