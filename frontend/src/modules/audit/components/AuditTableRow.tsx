import { Button } from '@/components/ui/button'
import { TableCell, TableRow } from '@/components/ui/table'
import { auditableLabel } from '@/modules/audit/auditPresentation'
import { AuditEventPill } from '@/modules/audit/components/AuditEventPill'
import type { IAudit } from '@/modules/audit/schema'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { FileDiff } from 'lucide-react'
import { memo } from 'react'

export const AuditTableRow = memo<IEntityTableRowProps<IAudit>>(({
  entity,
  isLoading,
  isDeleting,
  isSaving,
  onDetail,
}) => {
  const disabled = isLoading || isDeleting || isSaving
  return (
    <TableRow>
      <TableCell className="text-muted-foreground">
        {entity.user?.full_name ?? entity.user?.name ?? '—'}
      </TableCell>
      <TableCell className="font-mono text-muted-foreground tabular-nums">
        {displayFormatDate(entity.created_at)}
      </TableCell>
      <TableCell>
        <AuditEventPill event={entity.event} />
      </TableCell>
      <TableCell className="font-mono font-medium">
        {auditableLabel(entity)}
      </TableCell>
      <TableCell className="text-right">
        {onDetail ? (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onDetail(entity)}
            disabled={disabled}
            className="gap-2"
            aria-label={`Ver cambios del registro de auditoría ${auditableLabel(entity)}`}
          >
            <FileDiff className="h-4 w-4" aria-hidden />
            Ver cambios
          </Button>
        ) : null}
      </TableCell>
    </TableRow>
  )
}, (prevProps, nextProps) => {
  return checkMemoListProps<IAudit>(prevProps, nextProps)
})

AuditTableRow.displayName = 'AuditTableRow'
