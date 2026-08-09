import { Badge } from '@/components/ui/badge'
import { TableCell, TableRow } from '@/components/ui/table'
import type { ICompany } from '@/modules/company/schema'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { memo } from 'react'

export const CompanyTableRow = memo<IEntityTableRowProps<ICompany>>(({
  entity,
  canEdit,
  canDelete,
  isLoading,
  isDeleting,
  isSaving,
  onEdit,
  onDelete,
}) => {
  return (
    <TableRow key={entity.id}>
      <TableCell>
        <div className="font-medium">{entity.name}</div>
      </TableCell>
      <TableCell>{entity.legal_name ?? '—'}</TableCell>
      <TableCell>{entity.rfc ?? '—'}</TableCell>
      <TableCell>{entity.domain}</TableCell>
      <TableCell>{entity.industry ?? '—'}</TableCell>
      <TableCell className="text-center">
        {entity.is_verified ? (
          <Badge variant="default" className="text-xs">Verificada</Badge>
        ) : (
          <Badge variant="secondary" className="text-xs">No verificada</Badge>
        )}
      </TableCell>
      <TableCell className="actions">
        <div className="flex items-center justify-end gap-2">
          <ActionButtonGroup
            dropdown={true}
            onEdit={canEdit ? () => onEdit(entity) : undefined}
            edit={canEdit}
            remove={canDelete}
            onRemove={canDelete ? () => onDelete(entity) : undefined}
            disabled={isLoading || isDeleting || isSaving}
          />
        </div>
      </TableCell>
    </TableRow>
  )
}, (prevProps, nextProps) => {
  return checkMemoListProps<ICompany>(prevProps, nextProps)
})

CompanyTableRow.displayName = 'CompanyTableRow'
