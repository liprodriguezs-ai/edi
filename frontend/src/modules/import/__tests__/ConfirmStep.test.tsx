/**
 * Tests for ConfirmStep.
 *
 * Asserts:
 * - Spinner is shown while processing
 * - Progress bar is rendered and updates its width
 * - onComplete is called with the report when status = "ready"
 * - onComplete is called and amber banner shown when status = "completed_with_errors"
 * - Error banner is shown when status = "failed"
 * - Phase labels render correctly (reading_file → processing → finalizing)
 * - SCENARIO-06: POST /execute fires exactly once even under React StrictMode double-mount
 */
import { ConfirmStep } from '@/modules/import/components/ConfirmStep'
import { ImportResource } from '@/modules/import/services/ImportResource'
import type { IImportReport, IImportRun } from '@/modules/import/schema'
import { StrictMode } from 'react'
import { act, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// ─── Mocks ───────────────────────────────────────────────────────────────────

vi.mock('@/modules/import/services/ImportResource', () => ({
  ImportResource: {
    executeImport: vi.fn(),
    getStatus: vi.fn(),
  },
}))

// ─── Helpers ─────────────────────────────────────────────────────────────────

const mockFile = new File(['xlsx content'], 'pagos.xlsx', {
  type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
})

function buildRun(overrides: Partial<IImportRun> = {}): IImportRun {
  return {
    id: 'run-123',
    status: 'pending',
    file_path: null,
    progress: null,
    report: null,
    error: null,
    ...overrides,
  }
}

const baseReport: IImportReport = {
  total: 2,
  units_created: 1,
  residents_created: 1,
  payments_registered: 2,
  payments_skipped: 0,
  failed_count: 0,
  succeeded_count: 2,
  bank_balance_delta: [{ bank_account: 'Caja Principal', delta: 200000 }],
  rows: [],
}

const partialReport: IImportReport = {
  total: 10,
  units_created: 0,
  residents_created: 0,
  payments_registered: 8,
  payments_skipped: 0,
  failed_count: 2,
  succeeded_count: 8,
  bank_balance_delta: [],
  rows: [
    { row: 5, severity: 'error', column: 'importe', message: 'Monto inválido' },
    { row: 9, severity: 'error', column: 'folio', message: 'Unidad no encontrada' },
  ],
}

/**
 * Helper: mount ConfirmStep and advance time enough for executeImport + one poll to complete.
 * Uses fake timers for setInterval control.
 */
async function mountAndFlush(
  getStatusImpl: () => IImportRun,
  onComplete = vi.fn(),
) {
  vi.mocked(ImportResource.getStatus).mockImplementation(() => Promise.resolve(getStatusImpl()))

  const result = render(<ConfirmStep file={mockFile} onComplete={onComplete} />)

  // Flush: executeImport promise → setInterval registered → immediate pollStatus call
  await act(async () => {
    // Drain the microtask queue so executeImport resolves and pollStatus runs
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()
  })

  return { result, onComplete }
}

// ─── Test suite ──────────────────────────────────────────────────────────────

describe('ConfirmStep', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.mocked(ImportResource.executeImport).mockResolvedValue({ import_run_id: 'run-123' })
  })

  afterEach(() => {
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('shows spinner while processing', async () => {
    await mountAndFlush(() =>
      buildRun({ status: 'processing', progress: { processed: 1, total: 5 } }),
    )

    expect(screen.getByTestId('spinner')).toBeInTheDocument()
  })

  it('renders progress bar with correct width when processing', async () => {
    await mountAndFlush(() =>
      buildRun({ status: 'processing', progress: { processed: 3, total: 10 } }),
    )

    const progressBar = screen.getByTestId('progress-bar')
    // 3/10 = 30%
    expect(progressBar).toHaveStyle('width: 30%')
  })

  it('calls onComplete with the report when status is ready', async () => {
    const onComplete = vi.fn()
    await mountAndFlush(() => buildRun({ status: 'ready', report: baseReport }), onComplete)

    expect(onComplete).toHaveBeenCalledOnce()
    expect(onComplete).toHaveBeenCalledWith(baseReport)
  })

  it('shows success banner when import completes', async () => {
    await mountAndFlush(() => buildRun({ status: 'ready', report: baseReport }))

    expect(screen.getByTestId('success-banner')).toBeInTheDocument()
  })

  it('shows error banner when import fails', async () => {
    await mountAndFlush(() =>
      buildRun({ status: 'failed', error: 'Error de procesamiento.' }),
    )

    const banner = screen.getByTestId('error-banner')
    expect(banner).toBeInTheDocument()
    expect(banner).toHaveTextContent('Error de procesamiento.')
  })

  it('shows execute error when executeImport fails', async () => {
    vi.mocked(ImportResource.executeImport).mockRejectedValue(new Error('Network error'))

    render(<ConfirmStep file={mockFile} onComplete={vi.fn()} />)

    await act(async () => {
      await Promise.resolve()
      await Promise.resolve()
    })

    expect(screen.getByTestId('error-banner')).toBeInTheDocument()
  })

  // ─── Phase label tests ──────────────────────────────────────────────────────

  it('shows "Leyendo archivo…" label during reading_file phase', async () => {
    await mountAndFlush(() =>
      buildRun({
        status: 'processing',
        progress: { processed: 0, total: 0, phase: 'reading_file' },
      }),
    )

    expect(screen.getByTestId('phase-label')).toHaveTextContent('Leyendo archivo…')
  })

  it('shows "Procesando X / N filas…" during processing phase with known total', async () => {
    await mountAndFlush(() =>
      buildRun({
        status: 'processing',
        progress: { processed: 200, total: 1000, phase: 'processing' },
      }),
    )

    expect(screen.getByTestId('phase-label')).toHaveTextContent('Procesando 200 / 1000 filas…')
  })

  it('shows "Finalizando…" during finalizing phase', async () => {
    await mountAndFlush(() =>
      buildRun({
        status: 'processing',
        progress: { processed: 1000, total: 1000, phase: 'finalizing' },
      }),
    )

    expect(screen.getByTestId('phase-label')).toHaveTextContent('Finalizando…')
  })

  it('does not show "0 / 0 filas" when reading_file and total is 0', async () => {
    await mountAndFlush(() =>
      buildRun({
        status: 'processing',
        progress: { processed: 0, total: 0, phase: 'reading_file' },
      }),
    )

    // The progress label should NOT show "0 / 0 filas"
    const progressLabel = screen.getByTestId('progress-label')
    expect(progressLabel).not.toHaveTextContent('0 / 0 filas')
  })

  // ─── Partial-failure (completed_with_errors) tests ──────────────────────────

  it('calls onComplete with the report when status is completed_with_errors', async () => {
    const onComplete = vi.fn()
    await mountAndFlush(
      () => buildRun({ status: 'completed_with_errors', report: partialReport }),
      onComplete,
    )

    expect(onComplete).toHaveBeenCalledOnce()
    expect(onComplete).toHaveBeenCalledWith(partialReport)
  })

  it('shows amber partial-success banner when status is completed_with_errors', async () => {
    await mountAndFlush(() =>
      buildRun({ status: 'completed_with_errors', report: partialReport }),
    )

    const banner = screen.getByTestId('partial-success-banner')
    expect(banner).toBeInTheDocument()
    // Check amber color classes via class attribute
    expect(banner.className).toContain('amber')
    expect(banner).toHaveTextContent('2 filas no se pudieron procesar')
  })

  it('does NOT show green success banner when completed_with_errors', async () => {
    await mountAndFlush(() =>
      buildRun({ status: 'completed_with_errors', report: partialReport }),
    )

    expect(screen.queryByTestId('success-banner')).not.toBeInTheDocument()
  })

  it('shows succeeded_count and failed_count in partial banner', async () => {
    await mountAndFlush(() =>
      buildRun({ status: 'completed_with_errors', report: partialReport }),
    )

    const banner = screen.getByTestId('partial-success-banner')
    expect(banner).toHaveTextContent('8 registrados')
    expect(banner).toHaveTextContent('2 con error')
  })

  // ─── SCENARIO-06: StrictMode single-fire guard ──────────────────────────────

  it('SCENARIO-06: fires POST /execute exactly once under React StrictMode double-mount', async () => {
    vi.mocked(ImportResource.getStatus).mockResolvedValue(
      buildRun({ status: 'processing', progress: { processed: 0, total: 0 } }),
    )

    render(
      <StrictMode>
        <ConfirmStep file={mockFile} onComplete={vi.fn()} />
      </StrictMode>,
    )

    await act(async () => {
      // Drain microtasks for both mount attempts (StrictMode double-invokes effects)
      await Promise.resolve()
      await Promise.resolve()
      await Promise.resolve()
      await Promise.resolve()
    })

    // executeImport must be called exactly once, not twice
    expect(vi.mocked(ImportResource.executeImport)).toHaveBeenCalledTimes(1)
  })
})
