import {
  buildAnswerSchema,
  isValidAnswerForItem,
  requiredItemIds,
} from '@/modules/screening/utils/buildAnswerSchema'
import type { IBankItem } from '@/modules/screening/schema'
import { describe, expect, it } from 'vitest'

function item(overrides: Partial<IBankItem> = {}): IBankItem {
  return {
    screening_item_id: 'item-1',
    code: 'MG1',
    question: '¿Pregunta?',
    is_alternate: false,
    locked: false,
    locked_reason: null,
    options: [
      { screening_item_option_id: 'opt-a', label: 'Sí' },
      { screening_item_option_id: 'opt-b', label: 'No' },
    ],
    ...overrides,
  }
}

describe('buildAnswerSchema', () => {
  it('accepts an option that belongs to the item', () => {
    const schema = buildAnswerSchema([item()])
    const result = schema.safeParse({ 'item-1': 'opt-a' })
    expect(result.success).toBe(true)
  })

  it('rejects a foreign option id (REQ-SUI-011 scenario)', () => {
    const schema = buildAnswerSchema([item()])
    const result = schema.safeParse({ 'item-1': 'opt-c' })
    expect(result.success).toBe(false)
  })

  it('builds an independent enum per item', () => {
    const items = [
      item({ screening_item_id: 'item-1' }),
      item({
        screening_item_id: 'item-2',
        options: [{ screening_item_option_id: 'opt-x', label: 'Sí' }],
      }),
    ]
    const schema = buildAnswerSchema(items)
    expect(schema.safeParse({ 'item-1': 'opt-a', 'item-2': 'opt-x' }).success).toBe(true)
    expect(schema.safeParse({ 'item-1': 'opt-x' }).success).toBe(false)
  })
})

describe('isValidAnswerForItem', () => {
  it('validates a single answer against its item', () => {
    expect(isValidAnswerForItem(item(), 'opt-a')).toBe(true)
    expect(isValidAnswerForItem(item(), 'opt-z')).toBe(false)
  })
})

describe('requiredItemIds', () => {
  it('excludes locked items from local completeness (D4)', () => {
    const items = [
      item({ screening_item_id: 'item-1', locked: false }),
      item({ screening_item_id: 'item-2', locked: true }),
    ]
    expect(requiredItemIds(items)).toEqual(['item-1'])
  })
})
