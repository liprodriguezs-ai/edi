import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import type { IScreeningAreaResult } from '@/modules/screening/schema'
import { captureRank } from '@/modules/screening/utils/captureOrder'

interface SemaphoreTableProps {
  areaResults: IScreeningAreaResult[] | undefined
}

/**
 * REQ-SUI-006/008 — per-area results, pure presentational from
 * `area_results`. Never computes a color: renders exactly what the server
 * stored (pooled counts, thresholds, allows_yellow).
 */
export function SemaphoreTable({ areaResults }: SemaphoreTableProps) {
  if (!areaResults || areaResults.length === 0) {
    return <InlineEmpty>Aún sin calificar.</InlineEmpty>
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Área</TableHead>
          <TableHead>Color</TableHead>
          <TableHead className='hidden sm:table-cell'>Rojo</TableHead>
          <TableHead className='hidden sm:table-cell'>Amarillo</TableHead>
          <TableHead className='hidden sm:table-cell'>Verde</TableHead>
          <TableHead className='hidden md:table-cell'>Contestados (propios/alternos)</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {[...areaResults]
          .sort(
            (a, b) => captureRank(a.development_area?.code) - captureRank(b.development_area?.code)
          )
          .map((area) => (
            <TableRow key={area.id}>
              <TableCell className='font-medium'>{area.development_area?.name ?? '—'}</TableCell>
              <TableCell>
                <ResultColorPill colorKey={area.result_color?.key} />
              </TableCell>
              <TableCell className='hidden sm:table-cell'>{area.red_count}</TableCell>
              <TableCell className='hidden sm:table-cell'>{area.yellow_count}</TableCell>
              <TableCell className='hidden sm:table-cell'>{area.green_count}</TableCell>
              <TableCell className='hidden md:table-cell'>
                {area.answered_own_count} / {area.answered_alternate_count}
              </TableCell>
            </TableRow>
          ))}
      </TableBody>
    </Table>
  )
}
