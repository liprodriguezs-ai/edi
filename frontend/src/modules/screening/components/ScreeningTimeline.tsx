import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import type { IScreeningScoreRun } from '@/modules/screening/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'

interface ScreeningTimelineProps {
  scoreRuns: IScreeningScoreRun[] | undefined
}

const TRIGGER_LABELS: Record<string, string> = {
  initial: 'Calificación inicial',
  rescore: 'Recálculo',
  simulation: 'Simulación',
}

/** REQ-SUI-008 — history from `score_runs`: date, trigger, authoritative flag, version, engine, color, reason. */
export function ScreeningTimeline({ scoreRuns }: ScreeningTimelineProps) {
  if (!scoreRuns || scoreRuns.length === 0) {
    return <InlineEmpty>Sin corridas de calificación registradas.</InlineEmpty>
  }

  const ordered = [...scoreRuns].sort((a, b) => (a.created_at ?? '').localeCompare(b.created_at ?? '')).reverse()

  return (
    <ul className="space-y-3">
      {ordered.map((run) => (
        <li key={run.id} className="border border-border p-3">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{TRIGGER_LABELS[run.trigger] ?? run.trigger}</span>
            <StatusPill tone={run.is_authoritative ? 'b' : 'n'}>
              {run.is_authoritative ? 'Autoritativo' : 'Simulación'}
            </StatusPill>
            <ResultColorPill colorKey={run.result_color?.key} />
            <span className="text-xs text-muted-foreground">{displayFormatDate(run.created_at)}</span>
          </div>
          <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <span>Versión: {run.instrument_version?.key ?? '—'}</span>
            <span>Motor: {run.engine_version}</span>
          </div>
          {run.reason && <p className="mt-1 text-sm">{run.reason}</p>}
        </li>
      ))}
    </ul>
  )
}
