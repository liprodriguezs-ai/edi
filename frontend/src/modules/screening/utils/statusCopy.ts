import type { PillTone } from '@/shared/components/atoms/StatusPill'

/** UI-only Spanish labels for `screening_status.key` (not a color — REQ-SUI-006 only gates colors). */
const STATUS_LABELS: Record<string, string> = {
  draft: 'Borrador',
  scored: 'Calificada',
  completed: 'Cerrada',
  invalidated: 'Anulada',
}

/** The three steps every Prueba EDI goes through, in order. */
export const SCREENING_STEPS = ['Capturar respuestas', 'Revisar y calificar', 'Cerrar y agendar cita'] as const

export type CaptureView = 'capture' | 'review'

/**
 * Index of the current step in `SCREENING_STEPS` (`SCREENING_STEPS.length`
 * when all are done), or `null` for an annulled screening, which leaves the flow.
 */
export function screeningStepIndex(statusKey: string | undefined, view: CaptureView = 'capture'): number | null {
  if (statusKey === 'invalidated') return null
  if (statusKey === 'completed') return SCREENING_STEPS.length
  if (statusKey === 'scored') return 2
  return view === 'review' ? 1 : 0
}

export function screeningStatusLabel(key: string | undefined): string {
  return STATUS_LABELS[key ?? ''] ?? (key ?? '—')
}

export function canContinueApplication(statusKey: string | undefined): boolean {
  return statusKey === 'draft' || statusKey === 'scored'
}

/**
 * Non-clinical pill tone for `screening_status.key`. Never g/y/r for a lifecycle
 * state except `invalidated`, which keeps a danger meaning (it is not a result).
 */
export function screeningStatusTone(key: string | undefined): PillTone {
  if (key === 'invalidated') return 'r'
  if (key === 'completed') return 'b'
  return 'n'
}
