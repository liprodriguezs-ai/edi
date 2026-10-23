import { Button } from '@/components/ui/button'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { TenantFormModal } from '@/modules/tenant/TenantFormModal'
import { TenantBillingModal } from '@/modules/tenant/components/TenantBillingModal'
import { TenantTableRow } from '@/modules/tenant/components/TenantTableRow'
import { useTenantList } from '@/modules/tenant/hooks/useTenantList'
import { TenantStatusPill } from '@/modules/tenant/components/TenantStatusPill'
import type { ITenant } from '@/modules/tenant/schema'
import { useTenantStore } from '@/modules/tenant/store/tenantStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import {
  ActionButtonGroup,
  type ActionOption,
} from '@/shared/components/molecules/ActionButtonGroup'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { IconReceipt2 } from '@tabler/icons-react'
import { Plus } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const TENANT_PERMISSIONS = {
  canCreate: 'create.tenant',
  canEdit: 'update.tenant',
  canDelete: 'delete.tenant',
} as const

export const TenantList = () => {
  const navigate = useNavigate()
  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useTenantList()
  const { entities, pagination, isLoading } = useTenantStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useTenantStore((s) => s.isDeleting)

  const permissions = useMultiplePermissions(TENANT_PERMISSIONS)

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<ITenant | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<ITenant | null>(null)
  const [billingModalOpen, setBillingModalOpen] = useState(false)
  const [billingTenant, setBillingTenant] = useState<ITenant | null>(null)

  const handleCreate = useCallback(() => {
    navigate('/tenant/new')
  }, [navigate])

  const handleEdit = useCallback((tenant: ITenant) => {
    setSelectedEntity(tenant)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((tenant: ITenant) => {
    setEntityToDelete(tenant)
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

  const handleBilling = useCallback((tenant: ITenant) => {
    setBillingTenant(tenant)
    setBillingModalOpen(true)
  }, [])

  const handleModalClose = useCallback(() => {
    setModalOpen(false)
    setSelectedEntity(null)
  }, [])

  const tenantExtraOptions = useCallback(
    (tenant: ITenant) => {
      const options: ActionOption[] = []

      options.push({
        key: 'billing',
        label: 'Facturación',
        icon: <IconReceipt2 className='h-4 w-4' />,
        order: 4,
        onClick: () => handleBilling(tenant),
      })

      return options
    },
    [handleBilling]
  )

  const renderTenantActions = useCallback(
    (tenant: ITenant) => (
      <ActionButtonGroup
        dropdown
        onEdit={permissions.canEdit ? () => handleEdit(tenant) : undefined}
        edit={permissions.canEdit}
        remove={permissions.canDelete}
        onRemove={permissions.canDelete ? () => handleDeleteClick(tenant) : undefined}
        extraOptions={tenantExtraOptions(tenant)}
        disabled={isDeleting}
      />
    ),
    [
      permissions.canDelete,
      permissions.canEdit,
      handleDeleteClick,
      handleEdit,
      isDeleting,
      tenantExtraOptions,
    ]
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Tenants'
        description='Gestiona los espacios de trabajo del sistema'
        actions={
          <PermissionGuard permission='create.tenant'>
            <Button onClick={handleCreate} className='flex items-center gap-2'>
              <Plus className='h-4 w-4' />
              Nuevo tenant
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
            placeholder='Buscar tenants...'
          />

          <section>
            {isLoading ? (
              <div>
                <TableLoadingState />
              </div>
            ) : entities.length === 0 ? (
              <div>
                <TableEmptyState />
              </div>
            ) : (
              <>
                <ResponsiveDataTable<ITenant>
                  rows={entities}
                  breakpoint='lg'
                  header={
                    <TableHeader>
                      <TableRow>
                        <TableHead scope='col'>Nombre</TableHead>
                        <TableHead scope='col'>Estado</TableHead>
                        <TableHead scope='col' className='hidden md:table-cell'>
                          Método de pago
                        </TableHead>
                        <TableHead scope='col' className='hidden lg:table-cell'>
                          Propietario
                        </TableHead>
                        <TableHead scope='col' className='hidden lg:table-cell'>
                          Base de datos
                        </TableHead>
                        <TableHead scope='col' className='hidden md:table-cell'>
                          Fecha de creación
                        </TableHead>
                        <TableHead scope='col' className='text-right' />
                      </TableRow>
                    </TableHeader>
                  }
                  renderRow={(tenant) => (
                    <TenantTableRow
                      key={tenant.id}
                      entity={tenant}
                      canEdit={permissions.canEdit}
                      canDelete={permissions.canDelete}
                      isLoading={isLoading}
                      isDeleting={isDeleting}
                      onEdit={handleEdit}
                      onDelete={handleDeleteClick}
                      onBilling={handleBilling}
                    />
                  )}
                  renderCardTitle={(tenant) => tenant.name}
                  renderCardFields={(tenant) => [
                    {
                      label: 'Estado',
                      value: tenant.status ? (
                        <TenantStatusPill status={tenant.status} />
                      ) : (
                        '—'
                      ),
                    },
                    { label: 'Método de pago', value: tenant.payment_type?.name ?? '—' },
                    {
                      label: 'Propietario',
                      value: tenant.owners?.length
                        ? tenant.owners.map((owner) => owner.full_name).join(', ')
                        : '—',
                    },
                    { label: 'Creado', value: displayFormatDate(tenant.created_at) },
                  ]}
                  renderActions={renderTenantActions}
                />
                {entities.length > 0 && (
                  <Pagination pagination={pagination} onPageChanged={onPageChanged} />
                )}
              </>
            )}
          </section>
        </div>
      </section>

      <TenantFormModal
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

      <TenantBillingModal
        isOpen={billingModalOpen}
        tenant={billingTenant}
        onClose={() => {
          setBillingModalOpen(false)
          setBillingTenant(null)
        }}
      />
    </div>
  )
}
