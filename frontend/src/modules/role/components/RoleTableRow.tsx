import { Badge } from '@/components/ui/badge'
import { TableCell, TableRow } from '@/components/ui/table'
import type { IRole } from '@/modules/role/schema'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { memo } from 'react'
import { useNavigate } from 'react-router-dom'

export const RoleTableRow = memo<IEntityTableRowProps<IRole>>(({
  entity,
  canEdit,
  canDelete,
  isLoading,
  isDeleting,
  isSaving,
  onEdit,
  onDelete,
}) => {
  const navigate = useNavigate()
  const isSystem = entity.system
  const editAllowed = canEdit && !isSystem
  const deleteAllowed = canDelete && !isSystem

  return (
    <TableRow key={entity.id} className="cursor-pointer" onClick={() => navigate(`/role/${entity.id}`)}>
      <TableCell className="text-muted-foreground">
        {String(entity.description ?? '') || '—'}
      </TableCell>
      <TableCell>
        <div className="flex items-center gap-2 font-medium">
          {entity.name}
          {isSystem && (
            <Badge variant="secondary" className="text-xs">Sistema</Badge>
          )}
        </div>
      </TableCell>
      <TableCell>
        {displayFormatDate(entity.created_at)}
      </TableCell>
      <TableCell className="actions">
        <div className="flex items-center justify-end gap-2">
          <ActionButtonGroup
            dropdown={true}
            detail={true}
            onDetail={() => navigate(`/role/${entity.uuid}`)}
            onEdit={editAllowed ? () => onEdit(entity) : undefined}
            edit={editAllowed}
            remove={deleteAllowed}
            onRemove={deleteAllowed ? () => onDelete(entity) : undefined}
            disabled={isLoading || isDeleting || isSaving}
          />
        </div>
      </TableCell>
    </TableRow>
  )
}, (prevProps, nextProps) => checkMemoListProps<IRole>(prevProps, nextProps))

RoleTableRow.displayName = 'RoleTableRow'
