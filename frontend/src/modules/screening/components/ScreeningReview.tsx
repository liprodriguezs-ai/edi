import { Button } from '@/components/ui/button'
import type { AreaNavEntry } from '@/modules/screening/components/AreaNav'

interface ScreeningReviewProps {
  entries: Array<AreaNavEntry & { required: boolean; requiredAlternates: number }>
  missingCount: number
  isScoring: boolean
  disabled: boolean
  onBack: () => void
  onGoTo: (_anchorId: string) => void
  onScore: () => void
}

function rowText(entry: ScreeningReviewProps['entries'][number]): string {
  const missing = entry.total - entry.answered
  if (entry.status === 'needs_alternate') return `Falta el grupo anterior (${entry.answered} de ${entry.total})`
  if (missing > 0) return missing === 1 ? 'Falta 1 reactivo' : `Faltan ${missing} reactivos`
  if (entry.requiredAlternates > 0) return 'Completo · incluye grupo anterior'
  return 'Completo'
}

/**
 * Last step before scoring (GOV.UK "check answers"): every section with what
 * is left, a way back to each one, and "Calificar" only once nothing required
 * is missing. The result is still computed by the server.
 */
export function ScreeningReview({
  entries,
  missingCount,
  isScoring,
  disabled,
  onBack,
  onGoTo,
  onScore,
}: ScreeningReviewProps) {
  const canScore = missingCount === 0 && !disabled && !isScoring

  return (
    <section aria-labelledby="screening-review-title" className="space-y-4">
      <div className="space-y-1">
        <h2 id="screening-review-title" className="text-lg font-semibold">
          Revisión antes de calificar
        </h2>
        <p role="status" className={`text-sm ${missingCount > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'}`}>
          {missingCount > 0
            ? `Faltan ${missingCount} ${missingCount === 1 ? 'reactivo' : 'reactivos'} para poder calificar.`
            : 'Todo está contestado. Al calificar, el resultado lo calcula el servidor.'}
        </p>
      </div>

      <ul className="divide-y divide-border border border-border bg-card">
        {entries.map((entry) => {
          const incomplete = entry.status !== 'complete'
          return (
            <li key={entry.anchorId} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3">
              <span className="text-sm font-medium">{entry.label}</span>
              <span className="flex items-center gap-3">
                <span className={`text-sm ${incomplete && entry.required ? 'text-destructive' : 'text-muted-foreground'}`}>
                  {entry.required || !incomplete ? rowText(entry) : `${entry.answered} de ${entry.total} (opcional)`}
                </span>
                {incomplete && (
                  <Button
                    variant="outline"
                    size="sm"
                    aria-label={`Contestar ${entry.label}`}
                    onClick={() => onGoTo(entry.anchorId)}
                  >
                    Contestar
                  </Button>
                )}
              </span>
            </li>
          )
        })}
      </ul>

      <div className="flex flex-wrap gap-3">
        <Button variant="outline" onClick={onBack}>
          Volver a editar
        </Button>
        <Button onClick={onScore} disabled={!canScore}>
          {isScoring ? 'Calificando…' : 'Calificar'}
        </Button>
      </div>
    </section>
  )
}
