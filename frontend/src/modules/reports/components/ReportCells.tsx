import { TableCell } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { NutritionalStatusBadge } from '@/modules/nutrition/components/NutritionalStatusBadge'
import type {
  IChildReportNutrition,
  IChildReportScreening,
  IImpactAxis,
} from '@/modules/reports/schema'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { formatCentimeters, formatKilograms } from '@/modules/reports/utils/reportFormat'
import { displayFormatDate } from '@/shared/utils/dateHelper'

/** Sticky first column so the child stays visible while scrolling sideways. */
export const STICKY_CELL = 'sticky left-0 z-10 bg-card shadow-[1px_0_0_0_var(--border)]'

const EMPTY = <span className='text-muted-foreground'>—</span>

interface ScreeningCellsProps {
  screening: IChildReportScreening | null
  /** Axis columns to render (Concentrado EDI); omit for date + result only. */
  impactAxes?: IImpactAxis[]
}

/** Date + coloured result chip (+ one impact flag per axis) of one Prueba EDI. */
export function ScreeningCells({ screening, impactAxes = [] }: ScreeningCellsProps) {
  return (
    <>
      <TableCell className='font-mono tabular-nums whitespace-nowrap'>
        {screening ? displayFormatDate(screening.date, 'mediumDate') : EMPTY}
      </TableCell>
      <TableCell>
        {screening ? (
<ResultColorPill colorKey={screening.result.key} />
        ) : (
          EMPTY
        )}
      </TableCell>
      {impactAxes.map((axis) => {
        const impacted = screening?.impacts[axis.code] ?? false
        return (
          <TableCell
            key={axis.code}
            className={cn(
              'text-center text-xs font-semibold',
              impacted ? 'text-[var(--r)]' : 'text-muted-foreground'
            )}
            aria-label={impacted ? `${axis.name}: con impacto` : `${axis.name}: sin impacto`}
          >
            {impacted ? axis.code : '·'}
          </TableCell>
        )
      })}
    </>
  )
}

/** Fecha, peso, talla and the three WHO classifications of the latest measurement. */
export function NutritionCells({ nutrition }: { nutrition: IChildReportNutrition | null }) {
  return (
    <>
      <TableCell className='font-mono tabular-nums whitespace-nowrap'>
        {nutrition ? displayFormatDate(nutrition.measured_on, 'mediumDate') : EMPTY}
      </TableCell>
      <TableCell className='tabular-nums whitespace-nowrap'>
        {formatKilograms(nutrition?.weight_grams)}
      </TableCell>
      <TableCell className='tabular-nums whitespace-nowrap'>
        {formatCentimeters(nutrition?.height_mm)}
      </TableCell>
      <TableCell>
        <NutritionalStatusBadge status={nutrition?.weight_for_height_status} />
      </TableCell>
      <TableCell>
        <NutritionalStatusBadge status={nutrition?.weight_for_age_status} />
      </TableCell>
      <TableCell>
        <NutritionalStatusBadge status={nutrition?.height_for_age_status} />
      </TableCell>
    </>
  )
}
