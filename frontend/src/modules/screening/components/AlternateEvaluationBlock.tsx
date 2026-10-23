import { ItemRow } from '@/modules/screening/components/ItemRow'
import type { IBankItem } from '@/modules/screening/schema'
import { TriangleAlert } from 'lucide-react'

interface AlternateEvaluationBlockProps {
  /** development_area_id, so the page can scroll to the block that just appeared. */
  areaKey: string
  areaName: string
  items: IBankItem[]
  answers: Record<string, string>
  onAnswer: (_screeningItemId: string, _screeningItemOptionId: string) => void
  incompleteItemIds: string[]
}

/**
 * D5 — inserted under an `AreaSection` on 422 `ALTERNATE_ITEMS_REQUIRED`.
 * Answers travel through the exact same queue as own items (REQ-SUI-005).
 *
 * It names the area and states that the items are required, because a
 * generic warning left evaluators unsure what else they had to capture.
 * `data-alternate-block` lets the apply page scroll to the one that appeared.
 */
export function AlternateEvaluationBlock({
  areaKey,
  areaName,
  items,
  answers,
  onAnswer,
  incompleteItemIds,
}: AlternateEvaluationBlockProps) {
  if (items.length === 0) return null

  const answeredCount = items.filter((item) => answers[item.screening_item_id] !== undefined).length

  return (
    <div
      role="region"
      aria-label={`Grupo anterior de ${areaName}`}
      tabIndex={-1}
      data-alternate-block={areaKey}
      className="mt-3 scroll-mt-24 border border-[var(--y)] bg-[var(--ybg)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--y)]"
    >
      <div className="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-3 text-foreground">
        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
        <div className="min-w-0 flex-1 space-y-0.5">
          <p className="text-sm font-semibold">Aplica los reactivos del grupo anterior</p>
          <p className="text-sm">
            No se logró ningún reactivo de {areaName} en el grupo aplicado. Estos reactivos son obligatorios para
            calificar el eje.
          </p>
        </div>
        <span className="font-mono text-xs font-semibold tabular-nums">
          {answeredCount} de {items.length}
        </span>
      </div>
      <div className="divide-y divide-border border-t border-[var(--y)] bg-card">
        {items.map((item) => (
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
    </div>
  )
}
