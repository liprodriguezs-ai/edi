import type { IScreening, IScreeningItemBank } from '@/modules/screening/schema'
import { useScreeningDraftStore } from '@/modules/screening/store/screeningDraftStore'
import { beforeEach, describe, expect, it } from 'vitest'

function screening(overrides: Partial<IScreening> = {}): IScreening {
  return {
    id: 's-1',
    visit_id: 'v-1',
    child_id: 'c-1',
    instrument_version_id: 'iv-1',
    age_group_id: 'g-1',
    applied_age_group_id: 'g-1',
    chronological_age_days: 100,
    corrected_age_days: null,
    weight_grams: null,
    height_mm: null,
    head_circumference_mm: null,
    screening_status_id: 'st-draft',
    result_color_id: null,
    global_result_message_id: null,
    situation_id: null,
    alert_sign_count: null,
    risk_factor_count: null,
    engine_version: null,
    rules_fingerprint: null,
    scored_at: null,
    completed_at: null,
    previous_screening_id: null,
    previous_result_color_id: null,
    notes: null,
    screening_status: { id: 'st-draft', key: 'draft', name: 'Borrador' },
    ...overrides,
  }
}

const emptyBank: IScreeningItemBank = { applied_age_group_code: 'G3', areas: [], risk_factors: [] }

beforeEach(() => {
  useScreeningDraftStore.getState().reset()
})

describe('screeningDraftStore', () => {
  it('hydrates answers/risk factors from the screening payload', () => {
    const s = screening({
      answers: [
        {
          id: 'a-1',
          screening_id: 's-1',
          screening_item_id: 'item-1',
          screening_item_option_id: 'opt-a',
          result_color_id: 'color-green',
          is_auto_derived: false,
        },
      ],
      risk_factor_answers: [
        { id: 'rf-1', screening_id: 's-1', risk_factor_id: 'rf-item-1', is_present: true, is_auto_derived: false },
      ],
    })
    useScreeningDraftStore.getState().hydrate(s, emptyBank)

    const state = useScreeningDraftStore.getState()
    expect(state.answers['item-1']).toBe('opt-a')
    expect(state.riskFactorAnswers['rf-item-1']).toBe(true)
    expect(state.pending).toEqual({ answers: {}, riskFactors: {} })
  })

  it('hydrate marks the store frozen for completed/invalidated screenings', () => {
    useScreeningDraftStore.getState().hydrate(screening({ screening_status: { id: 'x', key: 'completed', name: 'Completado' } }), emptyBank)
    expect(useScreeningDraftStore.getState().syncStatus).toBe('frozen')
  })

  it('selectAnswer applies optimistically and enqueues (last-write-wins per item)', () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const { selectAnswer } = useScreeningDraftStore.getState()

    selectAnswer('item-1', 'opt-a')
    selectAnswer('item-1', 'opt-b') // overwrite before flush

    const state = useScreeningDraftStore.getState()
    expect(state.answers['item-1']).toBe('opt-b')
    expect(state.pending.answers['item-1']).toBe('opt-b')
  })

  it('takePendingSnapshot + clearPending only removes entries matching the flushed value (D3)', () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const { selectAnswer, takePendingSnapshot, clearPending } = useScreeningDraftStore.getState()

    selectAnswer('item-1', 'opt-a')
    const snapshot = takePendingSnapshot()

    // A NEW edit arrives while the batch built from `snapshot` is in flight.
    selectAnswer('item-1', 'opt-c')

    clearPending(snapshot)

    // The newer edit must survive — it was never part of the flushed snapshot.
    expect(useScreeningDraftStore.getState().pending.answers['item-1']).toBe('opt-c')
  })

  it('clearPending removes the entry once the flushed value matches exactly', () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const { selectAnswer, takePendingSnapshot, clearPending } = useScreeningDraftStore.getState()

    selectAnswer('item-1', 'opt-a')
    const snapshot = takePendingSnapshot()
    clearPending(snapshot)

    expect(useScreeningDraftStore.getState().pending.answers).toEqual({})
  })

  it('markFrozen sets frozen status and discards the pending queue', () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    useScreeningDraftStore.getState().selectAnswer('item-1', 'opt-a')

    useScreeningDraftStore.getState().markFrozen()

    const state = useScreeningDraftStore.getState()
    expect(state.syncStatus).toBe('frozen')
    expect(state.pending).toEqual({ answers: {}, riskFactors: {} })
  })

  it('clearResult clears result fields and reverts scorePhase to editing (staleness, REQ-SUI-007)', () => {
    useScreeningDraftStore.getState().hydrate(
      screening({
        result_color_id: 'color-green',
        result_color: { id: 'color-green', key: 'green' },
      }),
      emptyBank
    )
    expect(useScreeningDraftStore.getState().scorePhase).toBe('result')

    useScreeningDraftStore.getState().clearResult()

    const state = useScreeningDraftStore.getState()
    expect(state.scorePhase).toBe('editing')
    expect(state.screening?.result_color).toBeNull()
    expect(state.screening?.result_color_id).toBeNull()
  })

  it('insertAlternates merges inserted items per area and resets scorePhase to editing', () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    useScreeningDraftStore.getState().setScorePhase('scoring')

    useScreeningDraftStore.getState().insertAlternates([
      {
        development_area_id: 'area-mf',
        items: [
          {
            screening_item_id: 'alt-1',
            code: 'MF9.1',
            question: '¿Alterna?',
            is_alternate: true,
            locked: false,
            locked_reason: null,
            options: [{ screening_item_option_id: 'opt-a', label: 'SI' }],
          },
        ],
      },
    ])

    const state = useScreeningDraftStore.getState()
    expect(state.alternateRequired['area-mf']).toHaveLength(1)
    expect(state.scorePhase).toBe('editing')
  })

  it('updateScreeningHeader replaces the screening without touching bank/answers', () => {
    useScreeningDraftStore.getState().hydrate(
      screening({
        answers: [
          {
            id: 'a-1',
            screening_id: 's-1',
            screening_item_id: 'item-1',
            screening_item_option_id: 'opt-a',
            result_color_id: 'c',
            is_auto_derived: false,
          },
        ],
      }),
      emptyBank
    )

    useScreeningDraftStore.getState().updateScreeningHeader(
      screening({ result_color_id: 'color-red', result_color: { id: 'color-red', key: 'red' } })
    )

    const state = useScreeningDraftStore.getState()
    expect(state.screening?.result_color?.key).toBe('red')
    // Local optimistic answers are untouched by a header-only update.
    expect(state.answers['item-1']).toBe('opt-a')
  })

  it('clears the incomplete mark of an item as soon as it is answered', () => {
    const store = useScreeningDraftStore.getState()
    store.hydrate(screening(), emptyBank)
    store.setIncompleteItemIds(['item-1', 'item-2'])

    useScreeningDraftStore.getState().selectAnswer('item-1', 'opt-a')

    expect(useScreeningDraftStore.getState().incompleteItemIds).toEqual(['item-2'])
  })

  it('keeps the alternate items and unsaved answers when the same screening is re-hydrated', () => {
    const store = useScreeningDraftStore.getState()
    store.hydrate(screening(), emptyBank)
    const alternate = {
      screening_item_id: 'alt-1',
      code: 'MG-A1',
      question: '¿Rueda?',
      is_alternate: true,
      locked: false,
      locked_reason: null,
      options: [],
    }
    store.insertAlternates([{ development_area_id: 'area-mg', items: [alternate] }])
    useScreeningDraftStore.getState().setIncompleteItemIds(['item-9'])
    useScreeningDraftStore.getState().selectAnswer('item-1', 'opt-a')

    // e.g. a somatometry save refetches the same screening: the server does not
    // know the debounced answer yet.
    useScreeningDraftStore.getState().hydrate(screening({ weight_grams: 8200 }), emptyBank)

    const state = useScreeningDraftStore.getState()
    expect(state.alternateRequired['area-mg']).toEqual([alternate])
    expect(state.incompleteItemIds).toEqual(['item-9'])
    expect(state.answers['item-1']).toBe('opt-a')
    expect(state.pending.answers['item-1']).toBe('opt-a')
  })

  it('starts clean when a different screening is hydrated', () => {
    const store = useScreeningDraftStore.getState()
    store.hydrate(screening(), emptyBank)
    store.insertAlternates([{ development_area_id: 'area-mg', items: [] }])
    useScreeningDraftStore.getState().selectAnswer('item-1', 'opt-a')

    useScreeningDraftStore.getState().hydrate(screening({ id: 's-2' }), emptyBank)

    const state = useScreeningDraftStore.getState()
    expect(state.alternateRequired).toEqual({})
    expect(state.answers).toEqual({})
    expect(state.pending.answers).toEqual({})
  })

  it('replaces the alternate blocks with the server requirements and drops answers that stopped applying', () => {
    const alt = (id: string) => ({
      screening_item_id: id,
      code: id,
      question: id,
      is_alternate: true,
      locked: false,
      locked_reason: null,
      options: [],
    })
    const store = useScreeningDraftStore.getState()
    store.hydrate(screening(), emptyBank)
    store.setAlternateRequirements([
      { development_area_id: 'area-mf', code: 'MF', items: [alt('mf-a1'), alt('mf-a2')] },
      { development_area_id: 'area-le', code: 'LE', items: [alt('le-a1')] },
    ])
    useScreeningDraftStore.getState().selectAnswer('mf-a1', 'opt-si')
    useScreeningDraftStore.getState().selectAnswer('le-a1', 'opt-si')

    useScreeningDraftStore.getState().setAlternateRequirements([
      { development_area_id: 'area-le', code: 'LE', items: [alt('le-a1')] },
    ])

    const state = useScreeningDraftStore.getState()
    expect(Object.keys(state.alternateRequired)).toEqual(['area-le'])
    expect(state.answers['mf-a1']).toBeUndefined()
    expect(state.pending.answers['mf-a1']).toBeUndefined()
    expect(state.answers['le-a1']).toBe('opt-si')
  })
})

