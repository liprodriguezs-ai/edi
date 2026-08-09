import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { CollaboratorFormModal } from '@/modules/collaborator/CollaboratorFormModal'
import { CollaboratorTableRow } from '@/modules/collaborator/components/CollaboratorTableRow'
import { useCollaboratorList } from '@/modules/collaborator/hooks/useCollaboratorList'
import type { ICollaborator } from '@/modules/collaborator/schema'
import { EXPEDIENTE_CONFIG } from '@/modules/collaborator/schema'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import { useCollaboratorStore } from '@/modules/collaborator/store/collaboratorStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import type { ActionOption } from '@/shared/components/molecules/ActionButtonGroup'
import { ACTION_ORDER } from '@/shared/components/molecules/ActionButtonGroup'
import { CrudMode } from '@/shared/enums/CrudMode'
import { toast } from '@/shared/hooks/useToast'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Download, Loader2, Mail, Plus, Upload } from 'lucide-react'
import { useCallback, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const COLLABORATOR_PERMISSIONS = {
  canCreate: 'create.collaborator',
  canEdit: 'update.collaborator',
  canDelete: 'delete.collaborator',
  canReport: 'report.collaborator',
} as const

interface CollaboratorListProps {
  tableOnly?: boolean
  limit?: number
}

export const CollaboratorList = ({ tableOnly = false, limit }: CollaboratorListProps) => {
  const navigate = useNavigate()
  const { onPageChanged, onRemove, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useCollaboratorList({ limit })
  const { entities, pagination, isLoading, isDeleting, isSaving } = useCollaboratorStore(
    useShallow((s) => ({
      entities: s.entities,
      pagination: s.pagination,
      isLoading: s.isLoading,
      isDeleting: s.isDeleting,
      isSaving: s.isSaving,
    }))
  )

  const permissions = useMultiplePermissions(COLLABORATOR_PERMISSIONS)

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<ICollaborator | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<ICollaborator | null>(null)

  const handleResendInvitation = useCallback(async (collaborator: ICollaborator) => {
    try {
      await CollaboratorResource.resendInvitation(collaborator.id)
      toast.success({
        title: 'Invitación reenviada',
        description: 'Se ha enviado de nuevo el correo de invitación.',
      })
    } catch {
      toast.error({
        title: 'Error',
        description: 'No se pudo reenviar la invitación.',
      })
    }
  }, [])

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((collaborator: ICollaborator) => {
    setSelectedEntity(collaborator)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((collaborator: ICollaborator) => {
    setEntityToDelete(collaborator)
    setDeleteConfirmOpen(true)
  }, [])

  const handleDeleteConfirm = useCallback(async () => {
    if (!entityToDelete) return

    try {
      await onRemove(entityToDelete)
      setEntityToDelete(null)
      setDeleteConfirmOpen(false)
    } catch {
      // useBaseCrud.onDelete ya mostró el mensaje del backend; sólo evitamos el rechazo.
    }
  }, [entityToDelete, onRemove])

  const handleModalClose = useCallback(() => {
    setModalOpen(false)
    setSelectedEntity(null)
  }, [])

  const tableHeader = (
    <TableHeader>
      <TableRow>
        <TableHead scope='col'>Número</TableHead>
        <TableHead scope='col'>Colaborador</TableHead>
        <TableHead scope='col'>Puesto</TableHead>
        <TableHead scope='col'>Turno</TableHead>
        <TableHead scope='col'>Alta</TableHead>
        <TableHead scope='col'>Expediente</TableHead>
        <TableHead scope='col'>Contrato</TableHead>
        <TableHead scope='col'>Contratado</TableHead>
        {!tableOnly && (
          <TableHead scope='col' aria-label='Usuario de sistema'>
            Usuario
          </TableHead>
        )}
        <TableHead scope='col'>Fecha de creación</TableHead>
        {!tableOnly && <TableHead scope='col' className='text-right' />}
      </TableRow>
    </TableHeader>
  )

  const renderRow = (collaborator: ICollaborator) => (
    <CollaboratorTableRow
      tableOnly={tableOnly}
      key={collaborator.id}
      entity={collaborator}
      canEdit={permissions.canEdit}
      canDelete={permissions.canDelete}
      isLoading={isLoading}
      isDeleting={isDeleting}
      isSaving={isSaving}
      onEdit={handleEdit}
      onDelete={handleDeleteClick}
      onResendInvitation={handleResendInvitation}
    />
  )

  const renderCardTitle = (collaborator: ICollaborator) =>
    collaborator.full_name ?? String(collaborator.number)

  const renderCardFields = (collaborator: ICollaborator) => {
    const expedienteConfig = EXPEDIENTE_CONFIG[collaborator.expediente_status ?? 'incomplete']
    const hasDocTemplates = (collaborator.document_templates?.length ?? 0) > 0
    return [
      { label: 'Puesto', value: collaborator.employee_position?.name ?? '—' },
      { label: 'Turno', value: collaborator.employee_shift?.name ?? '—' },
      { label: 'Alta', value: collaborator.hire_date ? collaborator.hire_date.slice(0, 10) : '—' },
      {
        label: 'Expediente',
        value: <Badge className={expedienteConfig.className}>{expedienteConfig.label}</Badge>,
      },
      {
        label: 'Contrato',
        value: (
          <Badge variant={hasDocTemplates ? 'default' : 'secondary'}>
            {hasDocTemplates ? 'Sí' : 'No'}
          </Badge>
        ),
      },
      {
        label: 'Contratado',
        value: (
          <Badge variant={collaborator.contracted ? 'default' : 'secondary'}>
            {collaborator.contracted ? 'Sí' : 'No'}
          </Badge>
        ),
      },
      { label: 'Fecha de creación', value: displayFormatDate(collaborator.created_at) },
    ]
  }

  const renderActions = tableOnly
    ? undefined
    : (collaborator: ICollaborator) => {
        const extraOptions: ActionOption[] = []
        if (handleResendInvitation && collaborator.create_as_user && !collaborator.user_id) {
          extraOptions.push({
            key: 'resend_invitation',
            label: 'Reenviar invitación',
            icon: <Mail className='mr-2 h-5 w-5' />,
            order: ACTION_ORDER.EDIT - 1,
            onClick: () => handleResendInvitation(collaborator),
          })
        }
        return (
          <ActionButtonGroup
            dropdown
            detail
            onDetail={() => navigate(`/collaborator/${collaborator.id}`)}
            onEdit={permissions.canEdit ? () => handleEdit(collaborator) : undefined}
            edit={permissions.canEdit}
            remove={permissions.canDelete}
            onRemove={permissions.canDelete ? () => handleDeleteClick(collaborator) : undefined}
            disabled={isLoading || isDeleting || isSaving}
            extraOptions={extraOptions}
          />
        )
      }

  if (tableOnly) {
    return (
      <div className='rounded-lg border bg-card'>
        <div className='flex items-center justify-between px-6 py-4 border-b'>
          <h3 className='text-base font-semibold'>Colaboradores Recientes</h3>
          <Link to='/collaborator' className='text-sm font-medium text-primary hover:underline'>
            Ver todos
          </Link>
        </div>
        {isLoading ? (
          <div className='p-6'>
            <TableLoadingState />
          </div>
        ) : entities.length === 0 ? (
          <div className='p-6'>
            <TableEmptyState />
          </div>
        ) : (
          <ResponsiveDataTable<ICollaborator>
            rows={entities}
            breakpoint='lg'
            header={tableHeader}
            renderRow={renderRow}
            renderCardTitle={renderCardTitle}
            renderCardFields={renderCardFields}
            renderActions={renderActions}
            onRowClick={(c) => navigate(`/collaborator/${c.id}`)}
          />
        )}
      </div>
    )
  }

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Colaboradores'
        description='Gestiona los colaboradores del sistema'
        actions={
          <>
            <PermissionGuard permission='report.collaborator'>
              <Button
                variant='outline'
                onClick={() => onReport('Collaborator', 'xlsx', 'colaboradores')}
                disabled={isExporting}
                className='flex items-center gap-2'
              >
                {isExporting ? (
                  <Loader2 className='h-4 w-4 animate-spin' />
                ) : (
                  <Download className='h-4 w-4' />
                )}
                Descargar
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.collaborator'>
              <Button
                variant='outline'
                onClick={() => navigate('/collaborator/import')}
                className='flex items-center gap-2'
              >
                <Upload className='h-4 w-4' />
                Importar
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.collaborator'>
              <Button onClick={handleCreate} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                Nuevo colaborador
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <section>
        <div className='space-y-6'>
          <SearchList
            paramsSearch={paramsSearch}
            onSearch={onSearch}
            onReset={onReset}
            placeholder='Buscar colaboradores...'
          />

          <section>
            {isLoading ? (
              <TableLoadingState />
            ) : entities.length === 0 ? (
              <TableEmptyState />
            ) : (
              <>
                <ResponsiveDataTable<ICollaborator>
                  rows={entities}
                  breakpoint='lg'
                  header={tableHeader}
                  renderRow={renderRow}
                  renderCardTitle={renderCardTitle}
                  renderCardFields={renderCardFields}
                  renderActions={renderActions}
                  onRowClick={(c) => navigate(`/collaborator/${c.id}`)}
                />
                {entities.length > 0 && (
                  <Pagination pagination={pagination} onPageChanged={onPageChanged} />
                )}
              </>
            )}
          </section>
        </div>
      </section>

      <CollaboratorFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={selectedEntity ?? undefined}
        onClose={handleModalClose}
        onSuccess={handleModalClose}
      />

      <ConfirmDialog
        isOpen={deleteConfirmOpen}
        variant='delete'
        isLoading={isDeleting}
        entityName={
          entityToDelete
            ? [entityToDelete.name, entityToDelete.last_name, entityToDelete.second_last_name]
                .filter(Boolean)
                .join(' ') ||
              entityToDelete.full_name ||
              '—'
            : '—'
        }
        onConfirm={handleDeleteConfirm}
        onCancel={() => {
          setDeleteConfirmOpen(false)
          setEntityToDelete(null)
        }}
      />
    </div>
  )
}
