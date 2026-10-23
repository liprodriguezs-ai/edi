import { Button } from '@/components/ui/button'
import { TableCell, TableRow } from '@/components/ui/table'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { childFullName } from '@/modules/child/schema'
import type { IScreening } from '@/modules/screening/schema'
import { canContinueApplication, screeningStatusLabel, screeningStatusTone } from '@/modules/screening/utils/statusCopy'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { memo } from 'react'

interface ScreeningTableRowProps {
  entity: IScreening
  onContinue: (_entity: IScreening) => void
  onDetail: (_entity: IScreening) => void
  /**
   * Injected by ResponsiveDataTable from its onRowClick. A row component that
   * does not forward it swallows the prop silently: the list looks wired, the
   * rows carry cursor-pointer, and clicking does nothing.
   */
  onClick?: () => void
}

function situationLabel(entity: IScreening): string {
  return entity.situation?.name ?? '—'
}

/** REQ-SUI-002 — row actions per state; memoized to avoid full-table re-renders. */
export const ScreeningTableRow = memo<ScreeningTableRowProps>(({ entity, onContinue, onDetail, onClick }) => {
  const canContinue = canContinueApplication(entity.screening_status?.key)

  return (
    <TableRow key={entity.id} className={onClick ? 'cursor-pointer' : undefined} onClick={onClick}>
      <TableCell className="font-medium">
        {entity.child ? childFullName(entity.child) : '—'}
      </TableCell>
      <TableCell>
        {displayFormatDate(entity.visit?.visit_date, 'mediumDate')}
        {entity.visit?.receipt_number && (
          <span className="ml-1 text-muted-foreground">· {entity.visit.receipt_number}</span>
        )}
      </TableCell>
      <TableCell className="hidden md:table-cell">{entity.visit?.health_center?.name ?? '—'}</TableCell>
      <TableCell>
        <StatusPill tone={screeningStatusTone(entity.screening_status?.key)}>
          {screeningStatusLabel(entity.screening_status?.key)}
        </StatusPill>
      </TableCell>
      <TableCell>
        <ResultColorPill colorKey={entity.result_color?.key} />
      </TableCell>
      <TableCell className="hidden lg:table-cell">{situationLabel(entity)}</TableCell>
      <TableCell className="text-right">
        {canContinue ? (
          <Button variant="outline" size="sm" onClick={() => onContinue(entity)}>
            Continuar captura
          </Button>
        ) : (
          <Button variant="ghost" size="sm" onClick={() => onDetail(entity)}>
            Ver detalle
          </Button>
        )}
      </TableCell>
    </TableRow>
  )
}, (prev, next) => prev.entity === next.entity)

ScreeningTableRow.displayName = 'ScreeningTableRow'
