import { TableCell, TableRow } from '@/components/ui/table'
import type { IRole } from '@/modules/role/schema'
import type { ITenant } from '@/modules/tenant/schema'
import type { IUser } from '@/modules/user/schema'
import { AnonymizeUserAction } from '@/modules/user/components/AnonymizeUserAction'
import { RevokeSessionsAction } from '@/modules/user/components/RevokeSessionsAction'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { memo } from 'react'

/** Celda de identidad: avatar (imagen o inicial) + nombre completo. */
function UserIdentityCell({ user, fullName }: { user: IUser; fullName: string }) {
  return (
    <TableCell>
      <div className="flex items-center gap-3">
        {user.profile_image_url ? (
          <img src={user.profile_image_url} alt={fullName} className="h-8 w-8 rounded-full object-cover" />
        ) : (
          <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
            {user.name?.charAt(0)?.toUpperCase()}
          </div>
        )}
        <div className="font-medium">{fullName}</div>
      </div>
    </TableCell>
  )
}

/** Columna extra de organización, solo visible para super admin. */
function SuperAdminTenantCells({ user }: { user: IUser }) {
  const tenants = user.tenants ?? []
  if (tenants.length === 0) {
    return <TableCell>—</TableCell>
  }
  return <TableCell>{tenants.map((tenant: ITenant) => tenant.name).join(', ')}</TableCell>
}

export const UserTableRow = memo<IEntityTableRowProps<IUser>>(({
  entity,
  canEdit,
  canDelete,
  isLoading,
  isDeleting,
  isSaving,
  onEdit,
  onDelete,
}) => {
  const roles = useRoles()
  const fullName = entity.full_name
    ?? [entity.name, entity.last_name, entity.second_last_name].filter(Boolean).join(' ')

  return (
    <TableRow key={entity.id}>
      <UserIdentityCell user={entity} fullName={fullName} />
      <TableCell>{entity.email}</TableCell>
      <TableCell>{entity.roles?.map((role: IRole) => role.description).join(', ') ?? '—'}</TableCell>
      {roles?.hasRole(ERoleUserSlug.SUPER_ADMIN) && <SuperAdminTenantCells user={entity} />}
      <TableCell>{entity.phone ?? '—'}</TableCell>
      <TableCell>
        {displayFormatDate(entity.created_at)}
      </TableCell>
      <TableCell className="actions">
        <div className="flex items-center justify-end gap-1">
          <RevokeSessionsAction user={entity} />
          <AnonymizeUserAction user={entity} />
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
  );
}, (prevProps, nextProps) => {
  return checkMemoListProps<IUser>(prevProps, nextProps)
});

UserTableRow.displayName = 'UserTableRow';
