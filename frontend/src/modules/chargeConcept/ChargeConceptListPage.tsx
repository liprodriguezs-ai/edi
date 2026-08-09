import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ChargeConceptFormModal } from '@/modules/chargeConcept/ChargeConceptFormModal'
import { type IChargeConcept } from '@/modules/chargeConcept/schema'
import { ChargeConceptResource } from '@/modules/chargeConcept/services/ChargeConceptResource'
import { useChargeConceptStore } from '@/modules/chargeConcept/store/chargeConceptStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { ListChecks, Pencil, Plus } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const PERMISSIONS = {
  canCreate: 'create.charge_concept',
  canEdit: 'update.charge_concept',
  canReadPolicy: 'read.charge_policy',
} as const

export const ChargeConceptListPage = () => {
  const navigate = useNavigate()

  const { onPageChanged, onSearch, onReset, paramsSearch } = useBaseList<IChargeConcept>({
    store: useChargeConceptStore,
    resource: ChargeConceptResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'code', direction: 'asc' }],
      includes: [{ relation: 'category' }],
    },
  })
  const { entities, pagination, isLoading } = useChargeConceptStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const permissions = useMultiplePermissions(PERMISSIONS)

  const showActions = permissions.canEdit || permissions.canReadPolicy

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [editingConcept, setEditingConcept] = useState<IChargeConcept | null>(null)

  const handleCreate = useCallback(() => {
    setEditingConcept(null)
    setModalMode(CrudMode.CREATE)
    setIsModalOpen(true)
  }, [])

  const handleEdit = useCallback((concept: IChargeConcept) => {
    setEditingConcept(concept)
    setModalMode(CrudMode.EDIT)
    setIsModalOpen(true)
  }, [])

  const handleModalClose = useCallback(() => {
    setIsModalOpen(false)
    setEditingConcept(null)
  }, [])

  const policiesAction = (concept: IChargeConcept) =>
    permissions.canReadPolicy ? (
      <Button
        variant='ghost'
        size='icon'
        className='size-8'
        onClick={() => navigate(`/charge-concept/${concept.id}/policies`)}
        aria-label={`Programación de ${concept.code}`}
      >
        <ListChecks className='size-4' />
      </Button>
    ) : null

  const editAction = (concept: IChargeConcept) =>
    permissions.canEdit ? (
      <Button
        variant='ghost'
        size='icon'
        className='size-8'
        onClick={() => handleEdit(concept)}
        aria-label={`Editar concepto ${concept.code}`}
      >
        <Pencil className='size-4' />
      </Button>
    ) : null

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Conceptos de cobro'
        description='Identidad de los cobrables; el monto y la periodicidad viven en sus programaciones'
        actions={
          <PermissionGuard permission='create.charge_concept'>
            <Button className='rounded-full' onClick={handleCreate}>
              <Plus className='size-4' />
              Nuevo concepto
            </Button>
          </PermissionGuard>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar por clave o nombre…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          paramsSearch.search?.value ? (
            <TableEmptyState
              title='Sin resultados para tu búsqueda'
              description='Prueba con otra clave o nombre de concepto.'
            />
          ) : (
            <TableEmptyState
              title='Aún no hay conceptos'
              description='Crea el primero, p.ej. MANT_MENSUAL.'
            />
          )
        ) : (
          <>
            <ResponsiveDataTable<IChargeConcept>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Clave</TableHead>
                    <TableHead scope='col'>Nombre</TableHead>
                    <TableHead scope='col'>Categoría</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {showActions && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(concept) => (
                <TableRow key={concept.id}>
                  <TableCell className='font-mono text-[12.5px]'>{concept.code}</TableCell>
                  <TableCell className='font-medium'>{concept.name}</TableCell>
                  <TableCell>{concept.category?.name ?? '—'}</TableCell>
                  <TableCell>
                    <StatusPill tone={concept.is_active ? 'g' : 'r'}>
                      {concept.is_active ? 'Activo' : 'Inactivo'}
                    </StatusPill>
                  </TableCell>
                  {showActions && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <div className='flex items-center justify-end gap-1'>
                        {policiesAction(concept)}
                        {editAction(concept)}
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(concept) => (
                <span>
                  <span className='font-mono text-[12px] text-[var(--ink-3)]'>{concept.code}</span>{' '}
                  {concept.name}
                </span>
              )}
              renderCardFields={(concept) => [
                { label: 'Categoría', value: concept.category?.name ?? '—' },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={concept.is_active ? 'g' : 'r'}>
                      {concept.is_active ? 'Activo' : 'Inactivo'}
                    </StatusPill>
                  ),
                },
              ]}
              renderActions={
                showActions
                  ? (concept) => (
                      <div className='flex items-center gap-1'>
                        {policiesAction(concept)}
                        {editAction(concept)}
                      </div>
                    )
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ChargeConceptFormModal
        isOpen={isModalOpen}
        mode={modalMode}
        entity={editingConcept}
        onClose={handleModalClose}
      />
    </div>
  )
}
