import type { IBankArea, IBankItem } from '@/modules/screening/schema'
import { describe, expect, it } from 'vitest'
import { areaProgress } from './areaProgress'

function item(id: string, extra: Partial<IBankItem> = {}): IBankItem {
  return {
    screening_item_id: id,
    code: id,
    question: id,
    is_alternate: false,
    locked: false,
    locked_reason: null,
    options: [],
    ...extra,
  }
}

const area: IBankArea = {
  development_area_id: 'area-mg',
  code: 'MG',
  name: 'Motriz gruesa',
  items: [item('mg1'), item('mg2'), item('mg3', { locked: true }), item('mga1', { is_alternate: true })],
}

describe('areaProgress', () => {
  it('counts own items (locked ones as answered) and lists the missing ones', () => {
    expect(areaProgress(area, { mg1: 'x' }, undefined)).toEqual({
      answered: 2,
      total: 3,
      requiredAlternates: 0,
      answeredAlternates: 0,
      missingItemIds: ['mg2'],
      status: 'pending',
    })
  })

  it('needs the previous group while a required alternate is unanswered', () => {
    const progress = areaProgress(area, { mg1: 'x', mg2: 'x' }, [item('mga1', { is_alternate: true })])

    expect(progress.status).toBe('needs_alternate')
    expect(progress.total).toBe(4)
    expect(progress.missingItemIds).toEqual(['mga1'])
  })

  it('is complete when own and required alternate items are answered', () => {
    const progress = areaProgress(area, { mg1: 'x', mg2: 'x', mga1: 'x' }, [item('mga1', { is_alternate: true })])

    expect(progress.status).toBe('complete')
    expect(progress.answered).toBe(4)
  })
})
