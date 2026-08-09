import { Badge } from '@/components/ui/badge'
import { TableCell, TableRow } from '@/components/ui/table'
import { ACTION_ORDER, type ActionOption } from '@/shared/components/molecules/ActionButtonGroup'
import { EXPEDIENTE_CONFIG, type ExpedienteStatus, type ICollaborator } from '@/modules/collaborator/schema'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Mail, User, UserX } from 'lucide-react'
import { memo } from 'react'
import { useNavigate } from 'react-router-dom'

export type CollaboratorTableRowProps = IEntityTableRowProps<ICollaborator> & {
  onResendInvitation?: (_entity: ICollaborator) => void
}

function buildResendInvitationOption(
  entity: ICollaborator,
  onResendInvitation: (_entity: ICollaborator) => void,
): ActionOption[] {
  if (!entity.create_as_user || entity.user_id) return []
  return [
    {
      key: 'resend_invitation',
      label: 'Reenviar invitación',
      icon: <Mail className="mr-2 h-5 w-5" />,
      order: ACTION_ORDER.EDIT - 1,
      onClick: () => onResendInvitation(entity),
    },
  ]
}

function ExpedienteBadge({ status }: { status?: ExpedienteStatus }) {
  const config = EXPEDIENTE_CONFIG[status ?? 'incomplete']
  return <Badge className={config.className}>{config.label}</Badge>
}

function ContractBadge({ contracted }: { contracted?: boolean }) {
  return <Badge variant={contracted ? 'default' : 'secondary'}>{contracted ? 'Sí' : 'No'}</Badge>
}

function UserStatusCell({ hasUser }: { hasUser: boolean }) {
  return (
    <TableCell>
      {hasUser ? (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground" title="Tiene usuario de sistema" aria-label="Tiene usuario de sistema">
          <User className="h-4 w-4 text-green-500" />
        </span>
      ) : (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground" title="Sin usuario" aria-label="Sin usuario">
          <UserX className="h-4 w-4 text-red-500" />
        </span>
      )}
    </TableCell>
  )
}

function RowActionsCell({
  entity,
  canEdit,
  canDelete,
  isDisabled,
  extraOptions,
  onEdit,
  onDelete,
  onDetail,
}: {
  entity: ICollaborator
  canEdit: boolean
  canDelete: boolean
  isDisabled: boolean
  extraOptions: ActionOption[]
  onEdit: (_e: ICollaborator) => void
  onDelete: (_e: ICollaborator) => void
  onDetail: () => void
}) {
  return (
    <TableCell className="actions overflow-visible">
      <ActionButtonGroup
        dropdown
        detail
        onDetail={onDetail}
        onEdit={canEdit ? () => onEdit(entity) : undefined}
        edit={canEdit}
        remove={canDelete}
        onRemove={canDelete ? () => onDelete(entity) : undefined}
        disabled={isDisabled}
        extraOptions={extraOptions}
      />
    </TableCell>
  )
}

function CollaboratorRowContent({
  entity,
  canEdit,
  canDelete,
  isLoading,
  isDeleting,
  isSaving,
  onEdit,
  onDelete,
  onResendInvitation,
  tableOnly,
}: CollaboratorTableRowProps) {
  const navigate = useNavigate()
  const detailUrl = `/collaborator/${entity.id}`
  const isDisabled = Boolean(isLoading || isDeleting || isSaving)
  const hasUser = Boolean(entity.user_id)
  const extraOptions =
    onResendInvitation ? buildResendInvitationOption(entity, onResendInvitation) : []

  return (
    <TableRow className="cursor-pointer" onClick={() => navigate(detailUrl)}>
      <TableCell><span className="font-medium">{entity.number}</span></TableCell>
      <TableCell>
        <div className="font-medium">{entity.full_name}</div>
        <div className="text-xs text-muted-foreground">{entity.email}</div>
      </TableCell>
      <TableCell>{entity.employee_position?.name}</TableCell>
      <TableCell>{entity.employee_shift?.name}</TableCell>
      <TableCell>{entity.hire_date ? entity.hire_date.slice(0, 10) : '—'}</TableCell>
      <TableCell><ExpedienteBadge status={entity.expediente_status} /></TableCell>
      <TableCell>
        {(entity.document_templates?.length || 0) > 0 && <Badge variant="default">Sí</Badge>}
        {(entity.document_templates?.length || 0) === 0 && <Badge variant="secondary">No</Badge>}
      </TableCell>
      <TableCell><ContractBadge contracted={entity.contracted} /></TableCell>
      {!tableOnly && <UserStatusCell hasUser={hasUser} />}
      <TableCell>{displayFormatDate(entity.created_at)}</TableCell>
      {!tableOnly && (
        <RowActionsCell
          entity={entity}
          canEdit={Boolean(canEdit)}
          canDelete={Boolean(canDelete)}
          isDisabled={isDisabled}
          extraOptions={extraOptions}
          onEdit={onEdit}
          onDelete={onDelete}
          onDetail={() => navigate(detailUrl)}
        />
      )}
    </TableRow>
  )
}

export const CollaboratorTableRow = memo<CollaboratorTableRowProps>(
  (props) => <CollaboratorRowContent {...props} />,
  (prevProps, nextProps) =>
    checkMemoListProps<ICollaborator>(prevProps, nextProps) &&
    prevProps.onResendInvitation === nextProps.onResendInvitation,
)

CollaboratorTableRow.displayName = 'CollaboratorTableRow'
