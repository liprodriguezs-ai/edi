import { AlternateEvaluationBlock } from '@/modules/screening/components/AlternateEvaluationBlock'
import { ItemRow } from '@/modules/screening/components/ItemRow'
import type { IBankArea, IBankItem } from '@/modules/screening/schema'
import { areaProgress } from '@/modules/screening/utils/areaProgress'
import { areaAnchorId } from '@/modules/screening/utils/captureOrder'
import { Check } from 'lucide-react'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'

interface AreaSectionProps {
  area: IBankArea
  answers: Record<string, string>
  onAnswer: (_screeningItemId: string, _screeningItemOptionId: string) => void
  incompleteItemIds: string[]
  alternateItems: IBankItem[] | undefined
}

/**
 * REQ-SUI-003 — una sección por área del banco, orden del servidor, progreso
 * por área.
 *
 * Diseño: cabecera con kicker mono, contador y una barra de avance del área;
 * al completarse, el contador se vuelve una palomita índigo. Los reactivos se
 * apilan como celdas que se tocan por hairline, no como tarjetas flotando.
 */
export function AreaSection({ area, answers, onAnswer, incompleteItemIds, alternateItems }: AreaSectionProps) {
  const areaName = area.name ?? area.code ?? 'Área'
  const ownItems = area.items.filter((item) => !item.is_alternate)
  // Alternate items only count once the server asked for them; until then the
  // area is complete with its own items.
  const requiredAlternates = alternateItems ?? []
  const progress = areaProgress(area, answers, alternateItems)
  const alternatesPending = progress.status === 'needs_alternate'
  const answeredAlternates = progress.answeredAlternates
  const answeredCount = progress.answered
  const total = progress.total
  const complete = progress.status === 'complete'
  const pct = total > 0 ? Math.round((answeredCount / total) * 100) : 0

  return (
    <section id={areaAnchorId(area)} className="scroll-mt-28">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <SectionLabel as="h2">{areaName}</SectionLabel>
        {alternatesPending && (
          <span className="ml-auto font-mono text-[11px] font-semibold text-[var(--y)]">
            Requiere grupo anterior · {answeredAlternates}/{requiredAlternates.length}
          </span>
        )}
        {complete ? (
          <span className="inline-flex items-center gap-1 font-mono text-[11px] font-medium text-primary">
            <Check className="h-3.5 w-3.5" aria-hidden />
            {answeredCount}/{total}
          </span>
        ) : (
          <span className="font-mono text-[11px] tabular-nums text-muted-foreground">
            {answeredCount}/{total}
          </span>
        )}
      </div>

      {/* barra de avance del área */}
      <div className="mb-2.5 h-[3px] w-full overflow-hidden bg-border">
        <div
          className="h-full bg-primary transition-all duration-300"
          style={{ width: `${pct}%` }}
          aria-hidden
        />
      </div>

      <div className="divide-y divide-border border border-border bg-card">
        {ownItems.map((item) => (
          <ItemRow
            key={item.screening_item_id}
            item={item}
            value={answers[item.screening_item_id]}
            onChange={(optionId) => onAnswer(item.screening_item_id, optionId)}
            isIncomplete={
              incompleteItemIds.includes(item.screening_item_id) && answers[item.screening_item_id] === undefined
            }
          />
        ))}
      </div>

      <AlternateEvaluationBlock
        areaKey={area.development_area_id ?? 'unknown'}
        areaName={areaName}
        items={requiredAlternates}
        answers={answers}
        onAnswer={onAnswer}
        incompleteItemIds={incompleteItemIds}
      />
    </section>
  )
}
