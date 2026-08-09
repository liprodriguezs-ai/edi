import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PlanPriceMatrix } from '@/modules/billingPackage/components/PlanPriceMatrix'
import { BillingPackageFormModal } from '@/modules/billingPackage/BillingPackageFormModal'
import { useBillingPackageList } from '@/modules/billingPackage/hooks/useBillingPackageList'
import type { IBillingPackage } from '@/modules/billingPackage/schema'
import { useBillingPackageStore } from '@/modules/billingPackage/store/billingPackageStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import type { ActionOption } from '@/shared/components/molecules/ActionButtonGroup'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { LayoutGrid, Plus } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useShallow } from 'zustand/react/shallow'

const BILLING_PACKAGE_PERMISSIONS = {
  canCreate: 'create.billing_package',
  canEdit: 'update.billing_package',
  canDelete: 'delete.billing_package',
} as const

export const BillingPackageList = () => {
  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useBillingPackageList()
  const { entities, pagination, isLoading } = useBillingPackageStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useBillingPackageStore((s) => s.isDeleting)

  const permissions = useMultiplePermissions(BILLING_PACKAGE_PERMISSIONS)

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<IBillingPackage | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<IBillingPackage | null>(null)
  const [matrixOpen, setMatrixOpen] = useState(false)
  const [matrixPlan, setMatrixPlan] = useState<IBillingPackage | null>(null)

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((plan: IBillingPackage) => {
    setSelectedEntity(plan)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((plan: IBillingPackage) => {
    setEntityToDelete(plan)
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

  const handleOpenMatrix = useCallback((plan: IBillingPackage) => {
    setMatrixPlan(plan)
    setMatrixOpen(true)
  }, [])

  const handleModalClose = useCallback(() => {
    setModalOpen(false)
    setSelectedEntity(null)
  }, [])

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Planes de facturación'
        description='Administra los planes y el precio de cada uno por intervalo'
        actions={
          <PermissionGuard permission='create.billing_package'>
            <Button onClick={handleCreate} className='flex items-center gap-2'>
              <Plus className='h-4 w-4' />
              Nuevo plan
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
            placeholder='Buscar planes...'
          />

          <section>
            {isLoading ? (
              <TableLoadingState />
            ) : entities.length === 0 ? (
              <TableEmptyState />
            ) : (
              <>
                <ResponsiveDataTable<IBillingPackage>
                  rows={entities}
                  breakpoint='md'
                  header={
                    <TableHeader>
                      <TableRow>
                        <TableHead scope='col'>Plan</TableHead>
                        <TableHead scope='col' className='hidden sm:table-cell'>
                          Clave
                        </TableHead>
                        <TableHead scope='col' className='text-center'>
                          Público
                        </TableHead>
                        <TableHead scope='col' className='text-center'>
                          Activo
                        </TableHead>
                        <TableHead scope='col' className='hidden md:table-cell text-center'>
                          Orden
                        </TableHead>
                        <TableHead scope='col' className='text-right' />
                      </TableRow>
                    </TableHeader>
                  }
                  renderRow={(plan) => (
                    <tr key={plan.id} className='border-b transition-colors hover:bg-muted/50'>
                      <td className='p-4 align-middle'>
                        <div className='font-medium text-sm'>{plan.name}</div>
                        {plan.description && (
                          <div className='text-xs text-muted-foreground mt-0.5 line-clamp-1'>
                            {plan.description}
                          </div>
                        )}
                        {(plan.suggested_unit_min != null || plan.suggested_unit_max != null) && (
                          <div className='text-xs text-muted-foreground mt-0.5'>
                            {plan.suggested_unit_min ?? '—'} – {plan.suggested_unit_max ?? '∞'}{' '}
                            unidades
                          </div>
                        )}
                      </td>
                      <td className='p-4 align-middle hidden sm:table-cell'>
                        <code className='text-xs bg-muted px-1.5 py-0.5 rounded'>{plan.key}</code>
                      </td>
                      <td className='p-4 align-middle text-center'>
                        <Badge variant={plan.is_public ? 'default' : 'secondary'}>
                          {plan.is_public ? 'Público' : 'Privado'}
                        </Badge>
                      </td>
                      <td className='p-4 align-middle text-center'>
                        <Badge variant={plan.is_active ? 'default' : 'secondary'}>
                          {plan.is_active ? 'Activo' : 'Inactivo'}
                        </Badge>
                      </td>
                      <td className='p-4 align-middle text-center hidden md:table-cell text-sm text-muted-foreground'>
                        {plan.sort_order}
                      </td>
                      <td className='p-4 align-middle'>
                        <div className='flex items-center justify-end gap-2'>
                          {buildActionGroup(
                            plan,
                            permissions,
                            handleEdit,
                            handleDeleteClick,
                            handleOpenMatrix,
                            isDeleting
                          )}
                        </div>
                      </td>
                    </tr>
                  )}
                  renderCardTitle={(plan) => plan.name}
                  renderCardFields={(plan) => [
                    {
                      label: 'Clave',
                      value: (
                        <code className='text-xs bg-muted px-1.5 py-0.5 rounded'>{plan.key}</code>
                      ),
                    },
                    {
                      label: 'Público',
                      value: (
                        <Badge variant={plan.is_public ? 'default' : 'secondary'}>
                          {plan.is_public ? 'Público' : 'Privado'}
                        </Badge>
                      ),
                    },
                    {
                      label: 'Activo',
                      value: (
                        <Badge variant={plan.is_active ? 'default' : 'secondary'}>
                          {plan.is_active ? 'Activo' : 'Inactivo'}
                        </Badge>
                      ),
                    },
                    { label: 'Orden', value: plan.sort_order },
                  ]}
                  renderActions={(plan) =>
                    buildActionGroup(
                      plan,
                      permissions,
                      handleEdit,
                      handleDeleteClick,
                      handleOpenMatrix,
                      isDeleting
                    )
                  }
                />
                {entities.length > 0 && (
                  <Pagination pagination={pagination} onPageChanged={onPageChanged} />
                )}
              </>
            )}
          </section>
        </div>
      </section>

      <BillingPackageFormModal
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

      <PlanPriceMatrix
        isOpen={matrixOpen}
        plan={matrixPlan}
        onClose={() => {
          setMatrixOpen(false)
          setMatrixPlan(null)
        }}
      />
    </div>
  )
}

// ─── Helper: construye el ActionButtonGroup de forma consistente en desktop y mobile ──

type PermissionSnapshot = { canEdit: boolean; canDelete: boolean }

function buildActionGroup(
  plan: IBillingPackage,
  permissions: PermissionSnapshot,
  onEdit: (p: IBillingPackage) => void,
  onDelete: (p: IBillingPackage) => void,
  onMatrix: (p: IBillingPackage) => void,
  isDeleting: boolean
) {
  const extraOptions: ActionOption[] = [
    {
      key: 'price-matrix',
      label: 'Editar precios',
      icon: <LayoutGrid className='h-4 w-4' />,
      order: 5,
      onClick: () => onMatrix(plan),
    },
  ]

  return (
    <ActionButtonGroup
      dropdown
      onEdit={permissions.canEdit ? () => onEdit(plan) : undefined}
      edit={permissions.canEdit}
      remove={permissions.canDelete}
      onRemove={permissions.canDelete ? () => onDelete(plan) : undefined}
      extraOptions={extraOptions}
      disabled={isDeleting}
    />
  )
}
