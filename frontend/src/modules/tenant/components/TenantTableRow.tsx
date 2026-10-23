import { TableCell, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { TenantStatusPill } from '@/modules/tenant/components/TenantStatusPill'
import type { ITenant } from '@/modules/tenant/schema'
import type { IUser } from '@/modules/user/schema'
import { ObfuscatedText } from '@/shared/components/atoms/ObfuscatedText'
import { ActionButtonGroup, type ActionOption } from '@/shared/components/molecules/ActionButtonGroup'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { IconReceipt2 } from '@tabler/icons-react'
import { memo, useMemo } from 'react'

interface TenantTableRowProps extends IEntityTableRowProps<ITenant> {
  onBilling?: (_entity: ITenant) => void
}

export const TenantTableRow = memo<TenantTableRowProps>(
  ({ entity, canEdit, canDelete, isLoading, isDeleting, isSaving, onEdit, onDelete, onBilling }) => {
    const extraOptions = useMemo<ActionOption[]>(() => {
      const options: ActionOption[] = []

      if (onBilling) {
        options.push({
          key: 'billing',
          label: 'Facturación',
          icon: <IconReceipt2 className="h-4 w-4" />,
          order: 4,
          onClick: () => onBilling(entity),
        })
      }

      return options
    }, [onBilling, entity])

    return (
      <TableRow className={cn(entity.status === 'pendiente_pago' && 'bg-muted/50')}>
        <TableCell>
          <div className="font-medium">{entity.name}</div>
        </TableCell>
        <TableCell>
          {entity.status ? (
            <TenantStatusPill status={entity.status} />
          ) : '—'}
        </TableCell>
        <TableCell className="hidden md:table-cell text-muted-foreground">
          {entity.payment_type?.name ?? '—'}
        </TableCell>
        <TableCell className="hidden lg:table-cell text-muted-foreground">
          {entity.owners?.length && entity.owners?.length > 0 ? entity.owners?.map((owner: IUser) => owner.full_name).join(', ') : '—'}
        </TableCell>

        <TableCell className="hidden lg:table-cell text-muted-foreground">
          <ObfuscatedText value={entity.tenancy_db_name} />
        </TableCell>
        <TableCell className="hidden md:table-cell text-muted-foreground">
          {displayFormatDate(entity.created_at)}
        </TableCell>
        <TableCell className="actions">
          <div className="flex items-center justify-end gap-2">
            <ActionButtonGroup
              dropdown={true}
              onEdit={canEdit ? () => onEdit(entity) : undefined}
              edit={canEdit}
              remove={canDelete}
              onRemove={canDelete ? () => onDelete(entity) : undefined}
              extraOptions={extraOptions}
              disabled={isLoading || isDeleting || isSaving}
            />
          </div>
        </TableCell>
      </TableRow>
    )
  },
  (prevProps: TenantTableRowProps, nextProps: TenantTableRowProps) => {
    return checkMemoListProps<ITenant>(prevProps, nextProps)
  },
)

TenantTableRow.displayName = 'TenantTableRow'
