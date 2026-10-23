import { Button } from '@/components/ui/button'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { CompanyFormModal } from '@/modules/company/CompanyFormModal'
import { CompanyTableRow } from '@/modules/company/components/CompanyTableRow'
import { useCompanyList } from '@/modules/company/hooks/useCompanyList'
import type { ICompany } from '@/modules/company/schema'
import { useCompanyStore } from '@/modules/company/store/companyStore'
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
import { Plus } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useShallow } from 'zustand/react/shallow'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

const COMPANY_PERMISSIONS = {
  canCreate: 'create.company',
  canEdit: 'update.company',
  canDelete: 'delete.company',
} as const

export const CompanyList = () => {
  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useCompanyList()
  const { entities, pagination, isLoading } = useCompanyStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useCompanyStore((s) => s.isDeleting)

  const permissions = useMultiplePermissions(COMPANY_PERMISSIONS)

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<ICompany | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<ICompany | null>(null)

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((company: ICompany) => {
    setSelectedEntity(company)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((company: ICompany) => {
    setEntityToDelete(company)
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
        title='Empresas'
        description='Gestiona las empresas del sistema'
        actions={
          <PermissionGuard permission='create.company'>
            <Button onClick={handleCreate} className='flex items-center gap-2'>
              <Plus className='h-4 w-4' />
              Nueva empresa
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
            placeholder='Buscar empresas...'
          />

          <section>
            {isLoading ? (
              <TableLoadingState />
            ) : entities.length === 0 ? (
              <TableEmptyState />
            ) : (
              <>
                <ResponsiveDataTable<ICompany>
                  rows={entities}
                  breakpoint='lg'
                  header={
                    <TableHeader>
                      <TableRow>
                        <TableHead scope='col'>Nombre</TableHead>
                        <TableHead scope='col'>Razón social</TableHead>
                        <TableHead scope='col'>RFC</TableHead>
                        <TableHead scope='col'>Dominio</TableHead>
                        <TableHead scope='col'>Industria</TableHead>
                        <TableHead scope='col' className='text-center'>
                          Estatus
                        </TableHead>
                        <TableHead scope='col' className='text-right' />
                      </TableRow>
                    </TableHeader>
                  }
                  renderRow={(company) => (
                    <CompanyTableRow
                      key={company.id}
                      entity={company}
                      canEdit={permissions.canEdit}
                      canDelete={permissions.canDelete}
                      onEdit={handleEdit}
                      onDelete={handleDeleteClick}
                    />
                  )}
                  renderCardTitle={(company) => company.name}
                  renderCardFields={(company) => [
                    { label: 'Razón social', value: company.legal_name ?? '—' },
                    { label: 'RFC', value: <span className='font-mono'>{company.rfc ?? '—'}</span> },
                    { label: 'Dominio', value: <span className='font-mono'>{company.domain}</span> },
                    { label: 'Industria', value: company.industry ?? '—' },
                    {
                      label: 'Estatus',
                      value: company.is_verified ? (
                        <StatusPill tone='b'>Verificada</StatusPill>
                      ) : (
                        <StatusPill tone='n'>No verificada</StatusPill>
                      ),
                    },
                  ]}
                  renderActions={(company) => (
                    <ActionButtonGroup
                      dropdown
                      onEdit={permissions.canEdit ? () => handleEdit(company) : undefined}
                      edit={permissions.canEdit}
                      remove={permissions.canDelete}
                      onRemove={
                        permissions.canDelete ? () => handleDeleteClick(company) : undefined
                      }
                      disabled={isDeleting}
                    />
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

      <CompanyFormModal
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
