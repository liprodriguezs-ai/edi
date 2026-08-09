import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { TableCell, TableRow } from '@/components/ui/table'
import type { IAudit } from '@/modules/audit/schema'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { FileDiff } from 'lucide-react'
import { memo } from 'react'

const EVENT_LABELS: Record<IAudit['event'], string> = {
  created: 'Creado',
  updated: 'Actualizado',
  deleted: 'Eliminado',
  restored: 'Restaurado',
}

const EVENT_VARIANTS: Record<IAudit['event'], 'default' | 'secondary' | 'destructive' | 'outline'> = {
  created: 'default',
  updated: 'secondary',
  deleted: 'destructive',
  restored: 'outline',
}

function auditableLabel(audit: IAudit): string {
  const type = audit.auditable_type ?? '—'
  const shortType = type.split('\\').pop() ?? type
  return `${shortType} (${audit.auditable_id.slice(0, 8)}…)`
}

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
      <TableCell className="text-muted-foreground tabular-nums">
        {displayFormatDate(entity.created_at)}
      </TableCell>
      <TableCell>
        <Badge variant={EVENT_VARIANTS[entity.event]} className="text-xs">
          {EVENT_LABELS[entity.event]}
        </Badge>
      </TableCell>
      <TableCell className="font-medium">
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
