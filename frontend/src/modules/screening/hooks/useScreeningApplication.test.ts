/**
 * Characterization + regression tests for the autosave engine owned by
 * useScreeningApplication: debounce collapse, exponential backoff + queue
 * retention, the flush barrier used by score(), and timer cleanup on unmount.
 *
 * `ScreeningResource.show`/`items` are mocked to a promise that never
 * resolves: the hook always fires the background resume-hydrate on mount
 * (screeningId is passed so `currentScreeningId` is populated synchronously),
 * and letting that hydrate complete mid-test would silently reset the
 * store's `pending` queue and mask the very defects under test.
 */
import type { IScreening, IScreeningItemBank } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { useScreeningDraftStore } from '@/modules/screening/store/screeningDraftStore'
import { ApiError } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useScreeningApplication } from './useScreeningApplication'

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
  },
}))

vi.mock('@/modules/screening/services/ScreeningResource', () => ({
  ScreeningResource: {
    createDraft: vi.fn(),
    findByVisitId: vi.fn(),
    update: vi.fn(),
    show: vi.fn(() => new Promise(() => {})),
    items: vi.fn(() => new Promise(() => {})),
    answer: vi.fn(),
    score: vi.fn(),
  },
}))

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

/** Flushes the microtask queue several turns deep — needed after resolving a
 * manually-controlled mock promise, since the code under test chains a
 * second `await` (a re-entrant flushNow) before the barrier settles. */
async function flushMicrotasks(turns = 10) {
  for (let i = 0; i < turns; i++) await Promise.resolve()
}

beforeEach(() => {
  vi.useFakeTimers()
  useScreeningDraftStore.getState().reset()
  vi.mocked(ScreeningResource.answer).mockReset()
  vi.mocked(ScreeningResource.score).mockReset()
  vi.mocked(toast.error).mockClear()
  vi.mocked(toast.warning).mockClear()
  vi.mocked(toast.info).mockClear()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useScreeningApplication — autosave engine', () => {
  it('collapses rapid answers into one PATCH', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    vi.mocked(ScreeningResource.answer).mockResolvedValue({ data: screening() })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))

    act(() => {
      result.current.selectAnswer('item-1', 'opt-a')
      result.current.selectAnswer('item-2', 'opt-b')
      result.current.selectAnswer('item-3', 'opt-c')
    })

    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })

    expect(ScreeningResource.answer).toHaveBeenCalledTimes(1)
    const [, payload] = vi.mocked(ScreeningResource.answer).mock.calls[0]
    expect(payload.answers).toHaveLength(3)
  })

  it('retries with exponential backoff and retains the queue', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const answerMock = vi.mocked(ScreeningResource.answer)
    answerMock
      .mockRejectedValueOnce(new Error('network down'))
      .mockRejectedValueOnce(new Error('network down'))
      .mockResolvedValueOnce({ data: screening() })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))

    act(() => {
      result.current.selectAnswer('item-1', 'opt-a')
    })

    // Debounce fires at 750ms -> first attempt fails.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })
    expect(answerMock).toHaveBeenCalledTimes(1)
    expect(useScreeningDraftStore.getState().syncStatus).toBe('offline')
    expect(useScreeningDraftStore.getState().pending.answers['item-1']).toBe('opt-a')

    // First backoff = BACKOFF_INITIAL_MS (1000ms) -> second attempt fails.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1000)
    })
    expect(answerMock).toHaveBeenCalledTimes(2)
    expect(useScreeningDraftStore.getState().syncStatus).toBe('offline')
    expect(useScreeningDraftStore.getState().pending.answers['item-1']).toBe('opt-a')

    // Second backoff doubles to 2000ms -> third attempt succeeds.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2000)
    })
    expect(answerMock).toHaveBeenCalledTimes(3)
    expect(useScreeningDraftStore.getState().syncStatus).toBe('saved')
    expect(useScreeningDraftStore.getState().pending.answers).toEqual({})
  })

  it('flushBarrier resolves only after pending + dirty-during-flight work is flushed', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const answerMock = vi.mocked(ScreeningResource.answer)
    let resolveFirstSave!: (value: { data: IScreening }) => void
    answerMock.mockImplementationOnce(() => new Promise((resolve) => (resolveFirstSave = resolve)))
    answerMock.mockResolvedValueOnce({ data: screening() })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))

    act(() => {
      result.current.selectAnswer('item-1', 'opt-a')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })
    expect(answerMock).toHaveBeenCalledTimes(1) // first save in flight, not yet resolved

    act(() => {
      result.current.selectAnswer('item-2', 'opt-b') // dirties the store mid-flight
    })

    let barrierSettled = false
    let barrierPromise: Promise<unknown> = Promise.resolve()
    act(() => {
      barrierPromise = result.current.flushBarrier().then((r) => {
        barrierSettled = true
        return r
      })
    })
    expect(barrierSettled).toBe(false)

    await act(async () => {
      resolveFirstSave({ data: screening() })
      await flushMicrotasks()
    })

    expect(answerMock).toHaveBeenCalledTimes(2)
    await barrierPromise
    expect(barrierSettled).toBe(true)
    expect(useScreeningDraftStore.getState().pending.answers).toEqual({})
  })

  it('score() does not fire while the queue is unflushed after a failed save', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    vi.mocked(ScreeningResource.answer).mockRejectedValue(new Error('network down'))
    vi.mocked(ScreeningResource.score).mockResolvedValue({ data: screening() })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))

    act(() => {
      result.current.selectAnswer('item-1', 'opt-a')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })
    expect(vi.mocked(ScreeningResource.answer)).toHaveBeenCalledTimes(1)
    expect(useScreeningDraftStore.getState().syncStatus).toBe('offline')

    // Bounded so a still-buggy barrier (which lets score() proceed and hang
    // on the never-resolving `show` mock) fails fast instead of timing out.
    await act(async () => {
      const outcome = Promise.race([
        result.current.score().then(() => 'settled'),
        new Promise((resolve) => setTimeout(() => resolve('timeout'), 5000)),
      ])
      await vi.advanceTimersByTimeAsync(5000)
      await outcome
    })

    expect(vi.mocked(ScreeningResource.score)).not.toHaveBeenCalled()
    expect(vi.mocked(toast.error)).toHaveBeenCalledWith(
      'No se pudieron guardar tus respuestas; revisa tu conexión e intenta de nuevo'
    )
  })

  it('flushBarrier settles when the screening is frozen', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    useScreeningDraftStore.getState().markFrozen()

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))

    let outcome: string | undefined
    await act(async () => {
      const race = Promise.race([
        result.current.score().then(() => 'settled'),
        new Promise((resolve) => setTimeout(() => resolve('timeout'), 5000)),
      ])
      await vi.advanceTimersByTimeAsync(5000)
      outcome = (await race) as string
    })

    expect(outcome).toBe('settled')
    expect(vi.mocked(ScreeningResource.score)).not.toHaveBeenCalled()
  })

  it('unmount clears both timers', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const answerMock = vi.mocked(ScreeningResource.answer)
    answerMock.mockRejectedValue(new Error('network down'))

    const { result, unmount } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))

    act(() => {
      result.current.selectAnswer('item-1', 'opt-a')
    })
    // Debounce fires, save fails, a backoff retry gets scheduled.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })
    expect(answerMock).toHaveBeenCalledTimes(1)

    act(() => {
      result.current.selectAnswer('item-2', 'opt-c') // schedules a fresh debounce timer, never fires
    })

    unmount()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(60000) // well past any backoff cap
    })

    // One final flush of the queued edit when the page is left, and no retry
    // loop afterwards even though that flush fails too.
    expect(answerMock).toHaveBeenCalledTimes(2)
  })

  it('saves the queued answers right away when the page is left', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), emptyBank)
    const answerMock = vi.mocked(ScreeningResource.answer)
    answerMock.mockResolvedValue({ data: screening() })

    const { result, unmount } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))
    act(() => {
      result.current.selectAnswer('item-1', 'opt-a') // still inside the debounce window
    })

    unmount()
    await act(async () => {
      await flushMicrotasks()
    })

    expect(answerMock).toHaveBeenCalledTimes(1)
    const [, payload] = answerMock.mock.calls[0]
    expect(payload.answers).toEqual([{ screening_item_id: 'item-1', screening_item_option_id: 'opt-a' }])
  })
})

describe('useScreeningApplication — Prueba EDI a elección (S2)', () => {
  beforeEach(() => {
    vi.mocked(ScreeningResource.createDraft).mockReset()
    vi.mocked(ScreeningResource.createDraft).mockResolvedValue({ data: screening(), item_bank: emptyBank })
  })

  it('sends the chosen group with the draft', async () => {
    renderHook(() => useScreeningApplication({ visitId: 'v-1', ageGroupId: 'g-10' }))
    await act(async () => {
      await flushMicrotasks()
    })

    expect(ScreeningResource.createDraft).toHaveBeenCalledWith({ visit_id: 'v-1', age_group_id: 'g-10' })
  })

  it('warns that the chosen group was not used when the visit already has a screening', async () => {
    vi.mocked(ScreeningResource.createDraft).mockRejectedValue(
      new ApiError('Ya existe', 422, { message: 'Ya existe', code: 'VISIT_ALREADY_SCREENED' })
    )
    vi.mocked(ScreeningResource.findByVisitId).mockResolvedValue(screening({ id: 's-9' }))

    renderHook(() => useScreeningApplication({ visitId: 'v-1', ageGroupId: 'g-10' }))
    await act(async () => {
      await flushMicrotasks()
    })

    expect(vi.mocked(toast.info)).toHaveBeenCalledWith(
      'Esta visita ya tenía una Prueba EDI: se abrió esa y no se aplicó el grupo que elegiste.'
    )
  })

  it('opens the existing screening silently when no group was chosen', async () => {
    vi.mocked(ScreeningResource.createDraft).mockRejectedValue(
      new ApiError('Ya existe', 422, { message: 'Ya existe', code: 'VISIT_ALREADY_SCREENED' })
    )
    vi.mocked(ScreeningResource.findByVisitId).mockResolvedValue(screening({ id: 's-9' }))

    renderHook(() => useScreeningApplication({ visitId: 'v-1' }))
    await act(async () => {
      await flushMicrotasks()
    })

    expect(vi.mocked(toast.info)).not.toHaveBeenCalled()
  })

  it('keeps the age-derived payload when no group was chosen', async () => {
    renderHook(() => useScreeningApplication({ visitId: 'v-1' }))
    await act(async () => {
      await flushMicrotasks()
    })

    expect(ScreeningResource.createDraft).toHaveBeenCalledWith({ visit_id: 'v-1' })
  })
})

describe('useScreeningApplication — evaluación del grupo anterior', () => {
  it('names the areas that need the previous group when scoring asks for them', async () => {
    const bank: IScreeningItemBank = {
      applied_age_group_code: 'G7',
      areas: [
        { development_area_id: 'area-mg', code: 'MG', name: 'Motriz gruesa', items: [] },
        { development_area_id: 'area-le', code: 'LE', name: 'Lenguaje', items: [] },
      ],
      risk_factors: [],
    }
    useScreeningDraftStore.getState().hydrate(screening(), bank)
    vi.mocked(ScreeningResource.score).mockRejectedValue(
      new ApiError('Se requiere la evaluación alterna', 422, {
        message: 'Se requiere la evaluación alterna',
        code: 'ALTERNATE_ITEMS_REQUIRED',
        areas: [
          {
            development_area_id: 'area-mg',
            code: 'MG',
            missing_items: [{ screening_item_id: 'alt-1', code: 'MG-A1', question: '¿Rueda?', options: [] }],
          },
        ],
      })
    )

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))
    await act(async () => {
      await result.current.score()
    })

    expect(vi.mocked(toast.warning)).toHaveBeenCalledWith(
      'Motriz gruesa: no se logró ningún reactivo. Contesta los reactivos del grupo anterior para poder calificar.'
    )
    expect(useScreeningDraftStore.getState().alternateRequired['area-mg']).toHaveLength(1)
  })
})

describe('useScreeningApplication — grupo anterior en vivo', () => {
  const bank: IScreeningItemBank = {
    applied_age_group_code: 'G10',
    areas: [{ development_area_id: 'area-mf', code: 'MF', name: 'Motriz fina', items: [] }],
    risk_factors: [],
  }
  const altItem = {
    screening_item_id: 'mf-a1',
    code: 'MF9.1',
    question: '¿Pinza?',
    is_alternate: true,
    locked: false,
    locked_reason: null,
    options: [],
  }

  it('shows the previous group as soon as a save reports it', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), bank)
    vi.mocked(ScreeningResource.answer).mockResolvedValue({
      data: screening(),
      alternate_requirements: [{ development_area_id: 'area-mf', code: 'MF', items: [altItem] }],
    })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))
    act(() => {
      result.current.selectAnswer('mf-1', 'opt-no')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })

    expect(useScreeningDraftStore.getState().alternateRequired['area-mf']).toEqual([altItem])
    expect(vi.mocked(toast.warning)).toHaveBeenCalledWith(
      'Motriz fina: no se logró ningún reactivo. Contesta los reactivos del grupo anterior para poder calificar.'
    )
  })

  it('tells the evaluator when an axis no longer needs the previous group', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), bank)
    useScreeningDraftStore
      .getState()
      .setAlternateRequirements([{ development_area_id: 'area-mf', code: 'MF', items: [altItem] }])
    vi.mocked(ScreeningResource.answer).mockResolvedValue({ data: screening(), alternate_requirements: [] })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))
    act(() => {
      result.current.selectAnswer('mf-1', 'opt-si')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })

    expect(useScreeningDraftStore.getState().alternateRequired).toEqual({})
    expect(vi.mocked(toast.info)).toHaveBeenCalledWith(
      'Motriz fina ya no requiere el grupo anterior; se descartaron esas respuestas.'
    )
  })

  it('leaves the blocks alone when the save response carries no requirements', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), bank)
    useScreeningDraftStore
      .getState()
      .setAlternateRequirements([{ development_area_id: 'area-mf', code: 'MF', items: [altItem] }])
    vi.mocked(ScreeningResource.answer).mockResolvedValue({ data: screening() })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))
    act(() => {
      result.current.selectAnswer('mf-1', 'opt-si')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })

    expect(useScreeningDraftStore.getState().alternateRequired['area-mf']).toEqual([altItem])
  })

  it('ignores a save response that is already outdated by newer queued edits', async () => {
    useScreeningDraftStore.getState().hydrate(screening(), bank)
    useScreeningDraftStore
      .getState()
      .setAlternateRequirements([{ development_area_id: 'area-mf', code: 'MF', items: [altItem] }])
    vi.mocked(ScreeningResource.answer).mockImplementationOnce(async () => {
      // The evaluator keeps answering while this save is in flight.
      useScreeningDraftStore.getState().selectAnswer('mf-a1', 'opt-si')
      return { data: screening(), alternate_requirements: [] }
    })
    vi.mocked(ScreeningResource.answer).mockResolvedValue({
      data: screening(),
      alternate_requirements: [{ development_area_id: 'area-mf', code: 'MF', items: [altItem] }],
    })

    const { result } = renderHook(() => useScreeningApplication({ screeningId: 's-1' }))
    act(() => {
      result.current.selectAnswer('mf-1', 'opt-si')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })

    const state = useScreeningDraftStore.getState()
    expect(state.alternateRequired['area-mf']).toEqual([altItem])
    expect(state.answers['mf-a1']).toBe('opt-si')
    expect(vi.mocked(toast.info)).not.toHaveBeenCalled()
  })
})

