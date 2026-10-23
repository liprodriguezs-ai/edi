import type { IAlternateRequirement, IBankItem, IScreening, IScreeningItemBank } from '@/modules/screening/schema'
import { create } from 'zustand'
import { devtools } from 'zustand/middleware'

export type SyncStatus = 'idle' | 'saving' | 'saved' | 'offline' | 'frozen'
export type ScorePhase = 'editing' | 'scoring' | 'result'

interface PendingQueue {
  answers: Record<string, string>
  riskFactors: Record<string, boolean>
}

export interface ScreeningDraftState {
  screening: IScreening | null
  bank: IScreeningItemBank | null
  /** screening_item_id -> screening_item_option_id (optimistic, includes flushed + pending). */
  answers: Record<string, string>
  /** risk_factor_id -> is_present (optimistic). */
  riskFactorAnswers: Record<string, boolean>
  /** Flush queue (D3) — last-write-wins, cleared once a flush succeeds. */
  pending: PendingQueue
  syncStatus: SyncStatus
  scorePhase: ScorePhase
  /** development_area_id -> inserted alternate items (D5, 422 ALTERNATE_ITEMS_REQUIRED). */
  alternateRequired: Record<string, IBankItem[]>
  incompleteItemIds: string[]

  hydrate: (_screening: IScreening, _bank: IScreeningItemBank) => void
  /** Replaces ONLY the screening header (result fields after a score run) — keeps bank/answers as-is. */
  updateScreeningHeader: (_screening: IScreening) => void
  selectAnswer: (_screeningItemId: string, _screeningItemOptionId: string) => void
  selectRiskFactor: (_riskFactorId: string, _isPresent: boolean) => void
  takePendingSnapshot: () => PendingQueue
  clearPending: (_flushed: PendingQueue) => void
  setSyncStatus: (_status: SyncStatus) => void
  setScorePhase: (_phase: ScorePhase) => void
  markFrozen: () => void
  clearResult: () => void
  insertAlternates: (_areas: Array<{ development_area_id: string | null; items: IBankItem[] }>) => void
  /**
   * Server truth after an answer save / item-bank fetch: replaces every block,
   * and drops local answers of alternate items that stopped applying (the
   * server discarded them too).
   */
  setAlternateRequirements: (_requirements: IAlternateRequirement[]) => void
  setIncompleteItemIds: (_ids: string[]) => void
  reset: () => void
}

const EMPTY_PENDING: PendingQueue = { answers: {}, riskFactors: {} }

const initialState = {
  screening: null,
  bank: null,
  answers: {},
  riskFactorAnswers: {},
  pending: EMPTY_PENDING,
  syncStatus: 'idle' as SyncStatus,
  scorePhase: 'editing' as ScorePhase,
  alternateRequired: {},
  incompleteItemIds: [],
}

/**
 * D1/D3 — plain Zustand store (NOT createEntityStore: this is a workflow,
 * not an entity collection). Owns optimistic answer state + the pending
 * flush queue; `useScreeningApplication` owns timers/network.
 */
export const useScreeningDraftStore = create<ScreeningDraftState>()(
  devtools(
    (set, get) => ({
      ...initialState,

      hydrate: (screening, bank) =>
        set((state) => {
          // Re-hydrating the SAME screening (e.g. after a somatometry save) must
          // not drop what the server does not know yet: debounced answers still
          // in the queue, and the alternate items a previous score asked for.
          const isSameScreening = state.screening?.id === screening.id
          const pending = isSameScreening ? state.pending : EMPTY_PENDING

          const answers: Record<string, string> = {}
          for (const answer of screening.answers ?? []) {
            answers[answer.screening_item_id] = answer.screening_item_option_id
          }
          Object.assign(answers, pending.answers)
          const riskFactorAnswers: Record<string, boolean> = {}
          for (const rf of screening.risk_factor_answers ?? []) {
            riskFactorAnswers[rf.risk_factor_id] = rf.is_present
          }
          Object.assign(riskFactorAnswers, pending.riskFactors)

          const isFrozen = screening.screening_status?.key === 'completed' || screening.screening_status?.key === 'invalidated'
          const hasResult = Boolean(screening.result_color_id)

          return {
            screening,
            bank,
            answers,
            riskFactorAnswers,
            pending,
            syncStatus: isFrozen ? 'frozen' : 'idle',
            scorePhase: hasResult ? 'result' : 'editing',
            alternateRequired: isSameScreening ? state.alternateRequired : {},
            incompleteItemIds: isSameScreening ? state.incompleteItemIds : [],
          }
        }),

      updateScreeningHeader: (screening) =>
        set((state) => {
          const isFrozen =
            screening.screening_status?.key === 'completed' || screening.screening_status?.key === 'invalidated'
          return {
            screening,
            syncStatus: isFrozen ? 'frozen' : state.syncStatus,
          }
        }),

      selectAnswer: (screeningItemId, screeningItemOptionId) =>
        set((state) => ({
          answers: { ...state.answers, [screeningItemId]: screeningItemOptionId },
          pending: {
            ...state.pending,
            answers: { ...state.pending.answers, [screeningItemId]: screeningItemOptionId },
          },
          // The "missing" mark answers the last score attempt; once the item has
          // an answer it no longer applies.
          incompleteItemIds: state.incompleteItemIds.filter((id) => id !== screeningItemId),
        })),

      selectRiskFactor: (riskFactorId, isPresent) =>
        set((state) => ({
          riskFactorAnswers: { ...state.riskFactorAnswers, [riskFactorId]: isPresent },
          pending: {
            ...state.pending,
            riskFactors: { ...state.pending.riskFactors, [riskFactorId]: isPresent },
          },
        })),

      // Returns a SNAPSHOT of the current queue for the in-flight batch. The
      // queue is NOT cleared here — new edits made while the batch is in
      // flight must re-enter the queue (D3 in-flight guard) so a later flush
      // still sends them.
      takePendingSnapshot: () => get().pending,

      // Removes exactly the entries that were part of the flushed snapshot,
      // preserving any NEWER edits (last-write-wins: only clear if the
      // current pending value still equals what we flushed).
      clearPending: (flushed) =>
        set((state) => {
          const answers = { ...state.pending.answers }
          for (const [id, value] of Object.entries(flushed.answers)) {
            if (answers[id] === value) delete answers[id]
          }
          const riskFactors = { ...state.pending.riskFactors }
          for (const [id, value] of Object.entries(flushed.riskFactors)) {
            if (riskFactors[id] === value) delete riskFactors[id]
          }
          return { pending: { answers, riskFactors } }
        }),

      setSyncStatus: (status) => set({ syncStatus: status }),
      setScorePhase: (phase) => set({ scorePhase: phase }),

      markFrozen: () =>
        set({
          syncStatus: 'frozen',
          pending: EMPTY_PENDING,
        }),

      clearResult: () =>
        set((state) => ({
          scorePhase: 'editing',
          screening: state.screening
            ? {
                ...state.screening,
                result_color_id: null,
                result_color: null,
                situation_id: null,
                situation: null,
                alert_sign_count: null,
                risk_factor_count: null,
                global_result_message_id: null,
                global_result_message: null,
                scored_at: null,
                area_results: [],
                findings: [],
              }
            : null,
        })),

      insertAlternates: (areas) =>
        set((state) => {
          const next = { ...state.alternateRequired }
          for (const area of areas) {
            const key = area.development_area_id ?? 'unknown'
            next[key] = area.items
          }
          return { alternateRequired: next, scorePhase: 'editing' }
        }),

      setAlternateRequirements: (requirements) =>
        set((state) => {
          const next: Record<string, IBankItem[]> = {}
          for (const requirement of requirements) {
            next[requirement.development_area_id ?? 'unknown'] = requirement.items
          }

          const keptIds = new Set(Object.values(next).flat().map((item) => item.screening_item_id))
          const droppedIds = Object.values(state.alternateRequired)
            .flat()
            .map((item) => item.screening_item_id)
            .filter((id) => !keptIds.has(id))
          if (droppedIds.length === 0) return { alternateRequired: next }

          const answers = { ...state.answers }
          const pendingAnswers = { ...state.pending.answers }
          for (const id of droppedIds) {
            delete answers[id]
            delete pendingAnswers[id]
          }
          return {
            alternateRequired: next,
            answers,
            pending: { ...state.pending, answers: pendingAnswers },
            incompleteItemIds: state.incompleteItemIds.filter((id) => !droppedIds.includes(id)),
          }
        }),

      setIncompleteItemIds: (ids) => set({ incompleteItemIds: ids }),

      reset: () => set(initialState),
    }),
    { name: 'screeningDraft', enabled: import.meta.env.DEV }
  )
)
