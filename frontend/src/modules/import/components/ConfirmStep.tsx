import type { IImportReport, IImportRun, ImportPhase } from '@/modules/import/schema'
import { ImportResource } from '@/modules/import/services/ImportResource'
import {
  IconAlertCircle,
  IconAlertTriangle,
  IconCircleCheck,
  IconLoader2,
  IconRocket,
} from '@tabler/icons-react'
import { useEffect, useRef, useState } from 'react'

interface ConfirmStepProps {
  file: File
  onComplete: (report: IImportReport) => void
}

type ExecutionState = 'idle' | 'starting' | 'polling' | 'done' | 'done_with_errors' | 'failed'

const POLL_INTERVAL_MS = 2000

/**
 * Spanish labels per import phase.
 * The 'processing' phase augments this label with "X / N filas" below.
 * No nested ternaries per FE conventions.
 */
const PHASE_LABELS: Record<ImportPhase, string> = {
  reading_file: 'Leyendo archivo…',
  processing: 'Procesando filas…',
  finalizing: 'Finalizando…',
  done: 'Completado',
}

/**
 * Derive the progress label for the processing state.
 *
 * During `reading_file` (total === 0) we show an indeterminate label — never "0 / 0".
 * During `processing` with a known total we show "Procesando X / N filas…".
 * Falls back to a generic label for undefined phases or transitions.
 */
function buildProgressLabel(
  phase: ImportPhase | undefined,
  processed: number,
  total: number,
): string {
  if (!phase || phase === 'reading_file') {
    return PHASE_LABELS['reading_file']
  }
  if (phase === 'processing' && total > 0) {
    return `Procesando ${processed} / ${total} filas…`
  }
  if (phase === 'processing') {
    return PHASE_LABELS['processing']
  }
  return PHASE_LABELS[phase] ?? 'Procesando…'
}

/**
 * ConfirmStep — Step 3 of the import wizard.
 *
 * Flow:
 *  1. On mount: calls POST /import/execute → receives import_run_id.
 *     A `startedRef` guard ensures exactly ONE /execute fires even under
 *     React StrictMode's double-mount (which the existing `cancelled` flag
 *     alone did not prevent).
 *  2. Polls GET /import/{id}/status every 2 s.
 *  3. Terminal status mapping:
 *     - "ready"                → `done`            → green success banner
 *     - "completed_with_errors" → `done_with_errors` → amber warning banner
 *     - "failed"               → `failed`           → red error banner
 *  4. Phase labels update while polling so the user sees "Leyendo archivo…" →
 *     "Procesando X / N filas…" → "Finalizando…" in sequence.
 *
 * No manual "start" button — execution begins immediately on mount
 * so the user cannot accidentally re-execute the same file.
 */
export function ConfirmStep({ file, onComplete }: ConfirmStepProps) {
  const [state, setState] = useState<ExecutionState>('idle')
  const [progress, setProgress] = useState<{ processed: number; total: number; phase?: ImportPhase } | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [partialReport, setPartialReport] = useState<IImportReport | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const runIdRef = useRef<string | null>(null)

  /**
   * Single-fire guard — prevents a second POST /execute from React StrictMode's
   * double-mount. Unlike `cancelled`, this ref is NEVER reset in cleanup so a
   * remount of the same component instance does not re-trigger the side effect.
   */
  const startedRef = useRef(false)

  function clearPoll() {
    if (pollRef.current !== null) {
      clearInterval(pollRef.current)
      pollRef.current = null
    }
  }

  async function pollStatus() {
    const id = runIdRef.current
    if (!id) return

    let run: IImportRun
    try {
      run = await ImportResource.getStatus(id)
    } catch {
      // Transient network error — keep polling
      return
    }

    if (run.progress) {
      setProgress(run.progress)
    }

    if (run.status === 'ready') {
      clearPoll()
      setState('done')
      if (run.report) {
        onComplete(run.report)
      }
    } else if (run.status === 'completed_with_errors') {
      clearPoll()
      setState('done_with_errors')
      if (run.report) {
        setPartialReport(run.report)
        onComplete(run.report)
      }
    } else if (run.status === 'failed') {
      clearPoll()
      setState('failed')
      setErrorMessage(run.error ?? 'Error desconocido al procesar el archivo.')
    }
  }

  useEffect(() => {
    async function startExecution() {
      setState('starting')
      try {
        const { import_run_id } = await ImportResource.executeImport(file)

        runIdRef.current = import_run_id
        setState('polling')

        // (Re)start polling — clearPoll() first so we never leak a second interval.
        clearPoll()
        pollRef.current = setInterval(() => {
          void pollStatus()
        }, POLL_INTERVAL_MS)

        // Poll once right away without waiting for the first interval.
        await pollStatus()
      } catch {
        setState('failed')
        setErrorMessage('No se pudo iniciar la importación. Intenta de nuevo.')
      }
    }

    // Single-fire guard: run exactly ONE /execute even under React StrictMode's
    // double-mount. The cleanup below is ALWAYS registered (NOT gated by the guard)
    // so the poll interval is cleared on a real unmount. The previous version gated
    // the cleanup and used a `cancelled` flag that aborted the polling setup on
    // StrictMode's fake unmount — leaving the UI stuck on "Iniciando…" while the
    // import ran in the background.
    if (!startedRef.current) {
      startedRef.current = true
      void startExecution()
    }

    return () => clearPoll()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const progressPercent =
    progress && progress.total > 0
      ? Math.round((progress.processed / progress.total) * 100)
      : state === 'starting'
        ? 0
        : state === 'done' || state === 'done_with_errors'
          ? 100
          : 0

  const isProcessing = state === 'starting' || state === 'polling'

  const progressLabel = isProcessing
    ? buildProgressLabel(progress?.phase, progress?.processed ?? 0, progress?.total ?? 0)
    : null

  // During reading_file the total is 0 — show an indeterminate bar at 0% but
  // with the spinner and label, so the user never sees a stuck "0 / 0".
  const isIndeterminate = isProcessing && (!progress || progress.total === 0)

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-base font-semibold">Paso 3 — Procesando importación</h3>
        <p className="text-sm text-muted-foreground">
          El archivo se está procesando. Aguarda mientras se registran los pagos.
        </p>
      </div>

      {/* File name */}
      <p className="text-sm text-muted-foreground">
        Archivo: <span className="font-medium text-foreground">{file.name}</span>
      </p>

      {/* Status */}
      {isProcessing && (
        <div className="space-y-3">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <IconLoader2 className="h-4 w-4 animate-spin" data-testid="spinner" />
            <span data-testid="phase-label">
              {state === 'starting' ? 'Iniciando importación…' : (progressLabel ?? 'Procesando…')}
            </span>
          </div>

          {/* Progress bar */}
          <div>
            <div className="mb-1 flex justify-between text-xs text-muted-foreground">
              <span data-testid="progress-label">
                {isIndeterminate
                  ? 'Iniciando…'
                  : progress
                    ? `${progress.processed} / ${progress.total} filas`
                    : 'Iniciando…'}
              </span>
              <span>{isIndeterminate ? '' : `${progressPercent}%`}</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-primary transition-all duration-300"
                style={{ width: `${progressPercent}%` }}
                role="progressbar"
                aria-valuenow={isIndeterminate ? undefined : progressPercent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={progressLabel ?? 'Procesando importación'}
                data-testid="progress-bar"
              />
            </div>
          </div>
        </div>
      )}

      {/* Success (full) */}
      {state === 'done' && (
        <div
          className="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-400"
          data-testid="success-banner"
        >
          <IconCircleCheck className="h-4 w-4 shrink-0" />
          <span>Importación completada con éxito.</span>
        </div>
      )}

      {/* Partial success (completed_with_errors) */}
      {state === 'done_with_errors' && (
        <div
          className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-400"
          data-testid="partial-success-banner"
        >
          <IconAlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <div className="space-y-1">
            <p className="font-medium">
              Importación completada con errores:{' '}
              {partialReport?.failed_count ?? 0} fila
              {(partialReport?.failed_count ?? 0) !== 1 ? 's' : ''} no{' '}
              {(partialReport?.failed_count ?? 0) !== 1 ? 'se pudieron procesar' : 'se pudo procesar'}.
            </p>
            {partialReport && (
              <p className="text-xs">
                {partialReport.succeeded_count} registrado
                {partialReport.succeeded_count !== 1 ? 's' : ''},{' '}
                {partialReport.payments_skipped} omitido
                {partialReport.payments_skipped !== 1 ? 's' : ''},{' '}
                {partialReport.failed_count} con error.
              </p>
            )}
          </div>
        </div>
      )}

      {/* Error */}
      {state === 'failed' && (
        <div
          className="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400"
          data-testid="error-banner"
        >
          <IconAlertCircle className="h-4 w-4 shrink-0" />
          <span>{errorMessage}</span>
        </div>
      )}

      {/* Idle placeholder (should not be visible long) */}
      {state === 'idle' && (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <IconRocket className="h-4 w-4" />
          <span>Preparando importación…</span>
        </div>
      )}
    </div>
  )
}
