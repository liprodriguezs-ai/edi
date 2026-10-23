import type { IAlternateRequirement, IScreeningApiError } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { useScreeningDraftStore } from '@/modules/screening/store/screeningDraftStore'
import { screeningErrorCode, screeningErrorMessage } from '@/modules/screening/utils/screeningErrors'
import { ApiError } from '@/shared/api/apiClient'
import { useInitOnce } from '@/shared/hooks/useInitOnce'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

const DEBOUNCE_MS = 750
const BACKOFF_INITIAL_MS = 1000
const BACKOFF_CAP_MS = 15000

/** Area names from the loaded item bank (payloads only carry ids and codes). */
function areaNames(areas: Array<{ development_area_id: string | null; code: string | null }>): string[] {
  const bankAreas = useScreeningDraftStore.getState().bank?.areas ?? []
  return areas.map((area) => {
    const match = bankAreas.find((bankArea) => bankArea.development_area_id === area.development_area_id)
    return match?.name ?? area.code ?? 'Un eje'
  })
}

function listNames(names: string[]): string {
  return new Intl.ListFormat('es-MX', { type: 'conjunction' }).format(names)
}

function alternateRequiredMessage(areas: Array<{ development_area_id: string | null; code: string | null }>): string {
  const names = areaNames(areas)
  if (names.length === 0) return 'Contesta los reactivos del grupo anterior para poder calificar.'
  return `${listNames(names)}: no se logró ningún reactivo. Contesta los reactivos del grupo anterior para poder calificar.`
}

/**
 * Applies the server's current alternate requirements and tells the evaluator
 * what changed: an axis that now needs the previous group, or one that no
 * longer does (its alternate answers were discarded server-side).
 */
function applyAlternateRequirements(requirements: IAlternateRequirement[], { announce }: { announce: boolean }) {
  const store = useScreeningDraftStore.getState()
  const previousKeys = new Set(Object.keys(store.alternateRequired))
  const nextKeys = new Set(requirements.map((r) => r.development_area_id ?? 'unknown'))
  const added = requirements.filter((r) => !previousKeys.has(r.development_area_id ?? 'unknown'))
  const removed = [...previousKeys]
    .filter((key) => !nextKeys.has(key))
    .map((key) => ({ development_area_id: key, code: null }))

  store.setAlternateRequirements(requirements)
  if (!announce) return

  if (added.length > 0) toast.warning(alternateRequiredMessage(added))
  if (removed.length > 0) {
    const names = listNames(areaNames(removed))
    const verb = removed.length === 1 ? 'ya no requiere' : 'ya no requieren'
    toast.info(`${names} ${verb} el grupo anterior; se descartaron esas respuestas.`)
  }
}

export type WizardPhase ='loading' | 'not_applicable' | 'ready' | 'redirecting'

interface UseScreeningApplicationParams {
  visitId?: string
  screeningId?: string
  /** "Prueba EDI a elección" (S2): the official group picked by the evaluator. */
  ageGroupId?: string
}

/**
 * D1–D6, D13, D14 — owns create/resume hydration, the debounce/backoff
 * autosave engine, the flush barrier, `score()`, and freeze/staleness
 * convergence. `ScreeningApplyPage`/components stay presentational and read
 * everything from `useScreeningDraftStore`.
 */
export function useScreeningApplication({ visitId, screeningId, ageGroupId }: UseScreeningApplicationParams) {
  const navigate = useNavigate()
  const store = useScreeningDraftStore()

  const [phase, setPhase] = useState<WizardPhase>('loading')
  const [notApplicableMessage, setNotApplicableMessage] = useState<string | null>(null)

  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const backoffTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const backoffDelayRef = useRef(BACKOFF_INITIAL_MS)
  const inFlightRef = useRef(false)
  const dirtyDuringFlightRef = useRef(false)
  const flushWaitersRef = useRef<Array<(_result: { flushed: boolean }) => void>>([])
  const isUnmountedRef = useRef(false)

  const currentScreeningId = useRef<string | undefined>(screeningId)

  const resolveFlushWaiters = useCallback((flushed: boolean) => {
    const waiters = flushWaitersRef.current
    flushWaitersRef.current = []
    for (const resolve of waiters) resolve({ flushed })
  }, [])

  const flushNow = useCallback(async () => {
    const id = currentScreeningId.current
    const state = useScreeningDraftStore.getState()
    if (!id || state.syncStatus === 'frozen') {
      resolveFlushWaiters(false)
      return
    }

    const snapshot = state.takePendingSnapshot()
    const hasWork = Object.keys(snapshot.answers).length > 0 || Object.keys(snapshot.riskFactors).length > 0
    if (!hasWork) {
      resolveFlushWaiters(true)
      return
    }

    if (inFlightRef.current) {
      dirtyDuringFlightRef.current = true
      return
    }

    inFlightRef.current = true
    state.setSyncStatus('saving')

    try {
      const payload = {
        answers: Object.entries(snapshot.answers).map(([screening_item_id, screening_item_option_id]) => ({
          screening_item_id,
          screening_item_option_id,
        })),
        risk_factor_answers: Object.entries(snapshot.riskFactors).map(([risk_factor_id, is_present]) => ({
          risk_factor_id,
          is_present,
        })),
      }

      const response = await ScreeningResource.answer(id, payload)

      state.clearPending(snapshot)
      // With newer edits still queued, this response is already outdated (it
      // could drop a block the evaluator is filling right now); the next save
      // brings the current requirements.
      const { pending } = useScreeningDraftStore.getState()
      const hasNewerEdits = Object.keys(pending.answers).length > 0 || Object.keys(pending.riskFactors).length > 0
      if (response.alternate_requirements && !hasNewerEdits) {
        applyAlternateRequirements(response.alternate_requirements, { announce: true })
      }
      state.setSyncStatus('saved')
      backoffDelayRef.current = BACKOFF_INITIAL_MS

      // Staleness (REQ-SUI-007): a mutation while `scored` reverts the
      // screening to `draft` server-side. Reflect that optimistically.
      if (state.scorePhase === 'result') {
        state.clearResult()
        toast.info('El resultado quedó desactualizado; vuelve a calificar')
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        state.markFrozen()
        toast.error('Esta Prueba EDI está congelada y ya no admite cambios')
        setPhase('redirecting')
        navigate(`/screening/${id}`)
        resolveFlushWaiters(false)
        inFlightRef.current = false
        return
      }

      // Network/other failure: retain the queue, retry with backoff. A
      // barrier caller (score()) wants an honest answer now, not a wait of
      // up to BACKOFF_CAP_MS — resolve any waiters as failed and let the
      // scheduled retry keep working in the background.
      state.setSyncStatus('offline')
      const delay = Math.min(backoffDelayRef.current, BACKOFF_CAP_MS)
      backoffDelayRef.current = Math.min(delay * 2, BACKOFF_CAP_MS)
      if (!isUnmountedRef.current) backoffTimerRef.current = setTimeout(() => void flushNow(), delay)
      dirtyDuringFlightRef.current = false
      resolveFlushWaiters(false)
      return
    } finally {
      inFlightRef.current = false
    }

    if (dirtyDuringFlightRef.current) {
      dirtyDuringFlightRef.current = false
      void flushNow()
    } else {
      resolveFlushWaiters(true)
    }
  }, [navigate, resolveFlushWaiters])

  const scheduleFlush = useCallback(() => {
    if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current)
    debounceTimerRef.current = setTimeout(() => void flushNow(), DEBOUNCE_MS)
  }, [flushNow])

  /**
   * Awaited barrier: resolves once the CURRENT pending queue is fully
   * flushed (or settles honestly with `flushed: false` on frozen/failure —
   * see flushNow). Used before score().
   */
  const flushBarrier = useCallback(() => {
    if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current)
    return new Promise<{ flushed: boolean }>((resolve) => {
      flushWaitersRef.current.push(resolve)
      void flushNow()
    })
  }, [flushNow])

  const selectAnswer = useCallback(
    (screeningItemId: string, screeningItemOptionId: string) => {
      store.selectAnswer(screeningItemId, screeningItemOptionId)
      scheduleFlush()
    },
    [store, scheduleFlush]
  )

  const selectRiskFactor = useCallback(
    (riskFactorId: string, isPresent: boolean) => {
      store.selectRiskFactor(riskFactorId, isPresent)
      scheduleFlush()
    },
    [store, scheduleFlush]
  )

  // Best-effort flush when the tab is hidden (D3).
  useEffect(() => {
    const handleVisibility = () => {
      if (document.visibilityState === 'hidden') void flushNow()
    }
    document.addEventListener('visibilitychange', handleVisibility)
    return () => document.removeEventListener('visibilitychange', handleVisibility)
  }, [flushNow])

  // Leaving the page: stop every timer, then save whatever is still queued
  // once (an answer inside the debounce window would otherwise be lost). No
  // retry is scheduled after this point.
  const flushNowRef = useRef(flushNow)
  useEffect(() => {
    flushNowRef.current = flushNow
  }, [flushNow])
  useEffect(() => {
    isUnmountedRef.current = false
    return () => {
      isUnmountedRef.current = true
      if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current)
      if (backoffTimerRef.current) clearTimeout(backoffTimerRef.current)
      void flushNowRef.current()
    }
  }, [])

  const hydrateFromCreate = useCallback(async () => {
    if (!visitId) return
    try {
      const response = await ScreeningResource.createDraft(
        ageGroupId ? { visit_id: visitId, age_group_id: ageGroupId } : { visit_id: visitId }
      )
      currentScreeningId.current = response.data.id
      store.hydrate(response.data, response.item_bank)
      setPhase('ready')
      navigate(`/screening/${response.data.id}/apply`, { replace: true })
    } catch (err) {
      const code = screeningErrorCode(err)
      if (code === 'SCREENING_NOT_APPLICABLE') {
        setNotApplicableMessage(screeningErrorMessage(err))
        setPhase('not_applicable')
        return
      }
      if (code === 'VISIT_ALREADY_SCREENED') {
        // This lookup runs INSIDE the catch, so anything it throws escapes every
        // handler and the page renders nothing at all -- no message, no link to
        // the screening that does exist. Fall through to the error below instead,
        // which at least tells the user what happened.
        const existing = await ScreeningResource.findByVisitId(visitId).catch(() => null)
        if (existing) {
          if (ageGroupId) {
            toast.info('Esta visita ya tenía una Prueba EDI: se abrió esa y no se aplicó el grupo que elegiste.')
          }
          setPhase('redirecting')
          const target =
            existing.screening_status?.key === 'draft' || existing.screening_status?.key === 'scored'
              ? `/screening/${existing.id}/apply`
              : `/screening/${existing.id}`
          navigate(target, { replace: true })
          return
        }
      }
      toast.error(screeningErrorMessage(err, 'No se pudo iniciar la Prueba EDI'))
      setPhase('not_applicable')
      setNotApplicableMessage(screeningErrorMessage(err, 'No se pudo iniciar la Prueba EDI'))
    }
  }, [visitId, ageGroupId, store, navigate])

  const hydrateFromResume = useCallback(async () => {
    if (!screeningId) return
    try {
      const screening = await ScreeningResource.show(screeningId)
      const statusKey = screening.screening_status?.key
      if (statusKey === 'completed' || statusKey === 'invalidated') {
        setPhase('redirecting')
        navigate(`/screening/${screeningId}`, { replace: true })
        return
      }
      const items = await ScreeningResource.items(screeningId)
      currentScreeningId.current = screeningId
      store.hydrate(screening, items.item_bank)
      if (items.alternate_requirements) applyAlternateRequirements(items.alternate_requirements, { announce: false })
      setPhase('ready')
    } catch {
      toast.error('No se pudo cargar la Prueba EDI')
      setPhase('not_applicable')
      setNotApplicableMessage('No se pudo cargar la Prueba EDI.')
    }
  }, [screeningId, store, navigate])

  // Create-once, StrictMode-safe (REQ-SUI-003 scenario).
  useInitOnce(() => {
    if (visitId) {
      void hydrateFromCreate()
    } else if (screeningId) {
      void hydrateFromResume()
    }
  }, 'screening-application')

  const refetchAndHydrate = useCallback(async () => {
    const id = currentScreeningId.current
    if (!id) return
    const screening = await ScreeningResource.show(id)
    const items = await ScreeningResource.items(id)
    store.hydrate(screening, items.item_bank)
    if (items.alternate_requirements) applyAlternateRequirements(items.alternate_requirements, { announce: false })
  }, [store])

  const saveSomatometry = useCallback(
    async (values: { weight_grams?: number | null; height_mm?: number | null; head_circumference_mm?: number | null; notes?: string }) => {
      const id = currentScreeningId.current
      if (!id) return
      try {
        await ScreeningResource.update(id, values)
        // D14 — locked flags (head circumference) depend on somatometry; re-fetch items.
        await refetchAndHydrate()
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
          store.markFrozen()
          toast.error('Esta Prueba EDI está congelada y ya no admite cambios')
          return
        }
        throw err
      }
    },
    [refetchAndHydrate, store]
  )

  const score = useCallback(async () => {
    const id = currentScreeningId.current
    if (!id) return
    const { flushed } = await flushBarrier()
    if (!flushed) {
      const frozen = useScreeningDraftStore.getState().syncStatus === 'frozen'
      // When frozen, flushNow's 409 path (or markFrozen from saveSomatometry)
      // already toasted + redirected — avoid a double toast here.
      if (!frozen) toast.error('No se pudieron guardar tus respuestas; revisa tu conexión e intenta de nuevo')
      return
    }
    store.setScorePhase('scoring')
    try {
      await ScreeningResource.score(id)
      // D6 — re-fetch GET show with result includes and render from that
      // canonical shape, never from the score response body.
      const screening = await ScreeningResource.show(id)
      store.updateScreeningHeader(screening)
      store.setScorePhase('result')
    } catch (err) {
      const code = screeningErrorCode(err)
      if (code === 'ALTERNATE_ITEMS_REQUIRED' && err instanceof ApiError) {
        const payload = err.payload as unknown as Extract<IScreeningApiError, { code: 'ALTERNATE_ITEMS_REQUIRED' }>
        store.insertAlternates(
          (payload.areas ?? []).map((area) => ({
            development_area_id: area.development_area_id,
            items: area.missing_items.map((item) => ({
              screening_item_id: item.screening_item_id,
              code: item.code,
              question: item.question,
              is_alternate: true,
              locked: false,
              locked_reason: null,
              options: item.options,
            })),
          }))
        )
        toast.warning(alternateRequiredMessage(payload.areas ?? []))
        return
      }
      if (code === 'INCOMPLETE_ANSWERS' && err instanceof ApiError) {
        const payload = err.payload as unknown as Extract<IScreeningApiError, { code: 'INCOMPLETE_ANSWERS' }>
        store.setIncompleteItemIds(payload.missing_item_ids ?? [])
        store.setScorePhase('editing')
        toast.warning(screeningErrorMessage(err))
        return
      }
      if (err instanceof ApiError && err.status === 409) {
        store.markFrozen()
        toast.error('Esta Prueba EDI está congelada y ya no admite cambios')
        return
      }
      store.setScorePhase('editing')
      toast.error(screeningErrorMessage(err, 'No se pudo calificar la Prueba EDI'))
    }
  }, [flushBarrier, store])

  return {
    phase,
    notApplicableMessage,
    selectAnswer,
    selectRiskFactor,
    saveSomatometry,
    score,
    flushBarrier,
    screeningId: currentScreeningId.current,
  }
}
