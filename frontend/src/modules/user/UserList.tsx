import { Button } from '@/components/ui/button'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserFormModal } from '@/modules/user/UserFormModal'
import { UserTableRow } from '@/modules/user/components/UserTableRow'
import { AnonymizeUserAction } from '@/modules/user/components/AnonymizeUserAction'
import { RevokeSessionsAction } from '@/modules/user/components/RevokeSessionsAction'
import { useUserList } from '@/modules/user/hooks/useUserList'
import type { IUser } from '@/modules/user/schema'
import { useUserStore } from '@/modules/user/store/userStore'
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
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import type { IRole } from '@/modules/role/schema'
import type { ITenant } from '@/modules/tenant/schema'
import type { DateFilterForm } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Plus } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useShallow } from 'zustand/react/shallow'

const USER_PERMISSIONS = {
  canCreate: 'create.user',
  canEdit: 'update.user',
  canDelete: 'delete.user',
} as const

export const UserList = () => {
  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useUserList()
  const { entities, pagination, isLoading } = useUserStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useUserStore((s) => s.isDeleting)
  const roles = useRoles()
  useForm<DateFilterForm>({
    defaultValues: {
      date_start: '',
      date_end: '',
    },
  })

  const permissions = useMultiplePermissions(USER_PERMISSIONS)
  const isSuperAdmin = roles?.hasRole(ERoleUserSlug.SUPER_ADMIN) ?? false

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<IUser | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<IUser | null>(null)

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((user: IUser) => {
    setSelectedEntity(user)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((user: IUser) => {
    setEntityToDelete(user)
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
        title='Usuarios'
        description='Gestiona los usuarios del sistema'
        actions={
          <PermissionGuard permission='create.user'>
            <Button onClick={handleCreate} className='flex items-center gap-2'>
              <Plus className='h-4 w-4' />
              Nuevo usuario
            </Button>
          </PermissionGuard>
        }
      />

      <section>
        <div className='space-y-6'>
          <SearchList
            paramsSearch={paramsSearch}
            onSearch={onSearch}
            onReset={onReset}
            placeholder='Buscar usuarios...'
          ></SearchList>

          <section>
            {isLoading ? (
              <TableLoadingState />
            ) : entities.length === 0 ? (
              <TableEmptyState />
            ) : (
              <>
                <ResponsiveDataTable<IUser>
                  rows={entities}
                  breakpoint='lg'
                  header={
                    <TableHeader>
                      <TableRow>
                        <TableHead scope='col'>Nombre</TableHead>
                        <TableHead scope='col'>Correo</TableHead>
                        <TableHead scope='col'>Rol</TableHead>
                        {isSuperAdmin && <TableHead scope='col'>Tenant</TableHead>}
                        <TableHead scope='col'>Teléfono</TableHead>
                        <TableHead scope='col'>Fecha de creación</TableHead>
                        <TableHead scope='col' className='text-right' />
                      </TableRow>
                    </TableHeader>
                  }
                  renderRow={(user) => (
                    <UserTableRow
                      key={user.id}
                      entity={user}
                      canEdit={permissions.canEdit}
                      canDelete={permissions.canDelete}
                      onEdit={handleEdit}
                      onDelete={handleDeleteClick}
                    />
                  )}
                  renderCardTitle={(user) => {
                    const composed = [
                      user.name,
                      (user as IUser & { last_name?: string }).last_name,
                      (user as IUser & { second_last_name?: string }).second_last_name,
                    ]
                      .filter(Boolean)
                      .join(' ')
                    return user.full_name ?? (composed || user.name)
                  }}
                  renderCardFields={(user) => {
                    const fields = [
                      { label: 'Correo', value: user.email },
                      {
                        label: 'Rol',
                        value: user.roles?.map((r: IRole) => r.description).join(', ') ?? '—',
                      },
                      ...(isSuperAdmin
                        ? [
                            {
                              label: 'Tenant',
                              value:
                                (user.tenants ?? []).length > 0
                                  ? (user.tenants ?? []).map((t: ITenant) => t.name).join(', ')
                                  : '—',
                            },
                          ]
                        : []),
                      { label: 'Teléfono', value: user.phone ?? '—' },
                      { label: 'Fecha de creación', value: displayFormatDate(user.created_at) },
                    ]
                    return fields
                  }}
                  renderActions={(user) => (
                    <div className='flex items-center justify-end gap-1'>
                      <RevokeSessionsAction user={user} />
                      <AnonymizeUserAction user={user} />
                      <ActionButtonGroup
                        dropdown
                        onEdit={permissions.canEdit ? () => handleEdit(user) : undefined}
                        edit={permissions.canEdit}
                        remove={permissions.canDelete}
                        onRemove={permissions.canDelete ? () => handleDeleteClick(user) : undefined}
                        disabled={isDeleting}
                      />
                    </div>
                  )}
                />
                {entities.length > 0 && (
                  <Pagination pagination={pagination} onPageChanged={onPageChanged} />
                )}
              </>
            )}
          </section>
        </div>
      </section>

      <UserFormModal
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
