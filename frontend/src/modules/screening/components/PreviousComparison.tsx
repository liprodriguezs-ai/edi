import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import type { IScreening } from '@/modules/screening/schema'
import { ArrowRight } from 'lucide-react'

interface PreviousComparisonProps {
  screening: IScreening
}

/** REQ-SUI-008 — previous color → current color + situation label; `sis_code` as secondary text. */
export function PreviousComparison({ screening }: PreviousComparisonProps) {
  if (!screening.previous_screening_id) {
    return <InlineEmpty>Primera Prueba EDI de este niño — sin comparación previa.</InlineEmpty>
  }

  return (
    <div className="flex flex-wrap items-center gap-3 text-sm">
      <div className="flex items-center gap-2">
        <span className="text-muted-foreground">Anterior:</span>
        <ResultColorPill colorKey={screening.previous_result_color?.key} />
      </div>
      <ArrowRight className="h-4 w-4 text-muted-foreground" aria-hidden />
      <div className="flex items-center gap-2">
        <span className="text-muted-foreground">Actual:</span>
        <ResultColorPill colorKey={screening.result_color?.key} />
      </div>
      {screening.situation && (
        <span className="text-muted-foreground">
          · {screening.situation.name}
          {screening.situation.sis_code != null && ` (SIS ${screening.situation.sis_code})`}
        </span>
      )}
    </div>
  )
}
