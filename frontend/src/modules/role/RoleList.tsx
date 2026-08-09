import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { RoleFormModal } from '@/modules/role/RoleFormModal'
import { RoleTableRow } from '@/modules/role/components/RoleTableRow'
import { useRoleList } from '@/modules/role/hooks/useRoleList'
import type { IRole } from '@/modules/role/schema'
import { useRoleStore } from '@/modules/role/store/roleStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const ROLE_PERMISSIONS = {
  canCreate: 'create.role',
  canEdit: 'update.role',
  canDelete: 'delete.role',
} as const

/** Runtime shape: backend returns `description` (human label) and `uuid` alongside `id`. */
type IRoleRuntime = IRole & { description?: string; uuid?: string }

export const RoleList = () => {
  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useRoleList()
  const { entities, pagination, isLoading } = useRoleStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useRoleStore((s) => s.isDeleting)
  const navigate = useNavigate()

  const permissions = useMultiplePermissions(ROLE_PERMISSIONS)

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<IRole | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<IRole | null>(null)

  const handleEdit = useCallback((role: IRole) => {
    setSelectedEntity(role)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((role: IRole) => {
    setEntityToDelete(role)
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

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Roles'
        description='Gestiona los roles del sistema'
        actions={
          <PermissionGuard permission='create.role' children={undefined}>
            {/*  <Button onClick={handleCreate} className="flex items-center gap-2">
              <Plus className="h-4 w-4" />
              Nuevo rol
            </Button> */}
          </PermissionGuard>
        }
      />

      <section>
        <div className='space-y-6'>
          <SearchList
            paramsSearch={paramsSearch}
            onSearch={onSearch}
            onReset={onReset}
            placeholder='Buscar roles...'
          />

          <section>
            {isLoading ? (
              <TableLoadingState />
            ) : entities.length === 0 ? (
              <TableEmptyState />
            ) : (
              <>
                <ResponsiveDataTable<IRole>
                  rows={entities}
                  breakpoint='md'
                  onRowClick={(role) => navigate(`/role/${role.id}`)}
                  header={
                    <TableHeader>
                      <TableRow>
                        <TableHead scope='col'>Nombre</TableHead>
                        <TableHead scope='col'>Slug</TableHead>
                        <TableHead scope='col'>Fecha de creación</TableHead>
                        <TableHead scope='col' className='text-right' />
                      </TableRow>
                    </TableHeader>
                  }
                  renderRow={(role) => (
                    <RoleTableRow
                      key={role.id}
                      entity={role}
                      canEdit={permissions.canEdit}
                      canDelete={permissions.canDelete}
                      onEdit={handleEdit}
                      onDelete={handleDeleteClick}
                    />
                  )}
                  renderCardTitle={(role) => (role as IRoleRuntime).description ?? role.name}
                  renderCardFields={(role) => [
                    { label: 'Slug', value: role.name },
                    { label: 'Fecha de creación', value: displayFormatDate(role.created_at) },
                  ]}
                  renderActions={(role) => {
                    const r = role as IRoleRuntime
                    const editAllowed = permissions.canEdit && !r.system
                    const deleteAllowed = permissions.canDelete && !r.system
                    return (
                      <ActionButtonGroup
                        dropdown
                        detail
                        onDetail={() => navigate(`/role/${r.uuid ?? r.id}`)}
                        onEdit={editAllowed ? () => handleEdit(role) : undefined}
                        edit={editAllowed}
                        remove={deleteAllowed}
                        onRemove={deleteAllowed ? () => handleDeleteClick(role) : undefined}
                        disabled={isDeleting}
                      />
                    )
                  }}
                />
                {entities.length > 0 && (
                  <Pagination pagination={pagination} onPageChanged={onPageChanged} />
                )}
              </>
            )}
          </section>
        </div>
      </section>

      <RoleFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={selectedEntity ?? undefined}
        onClose={handleModalClose}
        onSuccess={handleModalClose}
      />

      <ConfirmDialog
        isOpen={deleteConfirmOpen}
        variant='delete'
        entityName={entityToDelete?.name}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => {
          setDeleteConfirmOpen(false)
          setEntityToDelete(null)
        }}
      />
    </div>
  )
}
