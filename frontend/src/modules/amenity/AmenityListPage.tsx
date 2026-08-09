import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import type { IAmenity } from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { useAmenityStore } from '@/modules/amenity/store/amenityStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayMoney } from '@/shared/utils/money'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

// S17 menu wiring: the sidebar's "Amenidades" entry always points to this
// admin CRUD page (single module row, plain 'amenity' permission_entity — see
// SystemTableSeeder::seedModulePermissionEntities). Residents hold read.amenity
// too (S2), so they can reach this route directly; a viewer without
// update.amenity has nothing to manage here and is sent to the resident
// catalog instead. Mirrors PackageListPage's redirect to /my-packages.
export const AmenityListPage = () => {
  const navigate = useNavigate()
  const canEdit = usePermissions('update.amenity')
  const canDelete = usePermissions('delete.amenity')

  const { onPageChanged, onSearch, onReset, onRemove, paramsSearch } = useBaseList<IAmenity>({
    store: useAmenityStore,
    resource: AmenityResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [{ relation: 'reservation_mode' }],
    },
  })
  const { entities, pagination, isLoading, isDeleting } = useAmenityStore(
    useShallow((s) => ({
      entities: s.entities,
      pagination: s.pagination,
      isLoading: s.isLoading,
      isDeleting: s.isDeleting,
    }))
  )

  const [toDelete, setToDelete] = useState<IAmenity | null>(null)

  const handleDeleteConfirm = async () => {
    if (!toDelete) return
    try {
      await onRemove(toDelete)
      setToDelete(null)
    } catch {
      // el toast global ya lo maneja
    }
  }

  // Resident/renter: neither update.amenity nor delete.amenity → nothing to
  // manage on this admin CRUD list, send them to the browse catalog.
  if (!canEdit && !canDelete) {
    return <Navigate to='/amenities' replace />
  }

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Amenidades'
        description='Espacios reservables del condominio'
        actions={
          <PermissionGuard permission='create.amenity'>
            <Button className='rounded-full' onClick={() => navigate('/amenity/new')}>
              <Plus className='size-4' />
              Nueva amenidad
            </Button>
          </PermissionGuard>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar amenidades…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Sin amenidades registradas'
            description='Crea la primera amenidad reservable del condominio.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IAmenity>
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Amenidad</TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Capacidad
                    </TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Modo
                    </TableHead>
                    <TableHead scope='col'>Costo</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='text-right'></TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(amenity) => (
                <TableRow key={amenity.id}>
                  <TableCell className='font-medium'>{amenity.name}</TableCell>
                  <TableCell className='hidden md:table-cell tabular-nums'>
                    {amenity.capacity}
                  </TableCell>
                  <TableCell className='hidden md:table-cell'>
                    {amenity.reservation_mode?.name ?? '—'}
                  </TableCell>
                  <TableCell>
                    {amenity.has_cost && amenity.cost_amount != null
                      ? displayMoney(amenity.cost_amount)
                      : 'Sin costo'}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={amenity.out_of_service ? 'r' : 'g'}>
                      {amenity.out_of_service ? 'Fuera de servicio' : 'Disponible'}
                    </StatusPill>
                  </TableCell>
                  <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                    <div className='flex justify-end gap-1.5'>
                      {canEdit && (
                        <Button
                          variant='ghost'
                          size='icon'
                          className='size-8'
                          onClick={() => navigate(`/amenity/${amenity.id}/edit`)}
                          aria-label={`Editar ${amenity.name}`}
                        >
                          <Pencil className='size-4' />
                        </Button>
                      )}
                      {canDelete && (
                        <Button
                          variant='ghost'
                          size='icon'
                          className='size-8'
                          onClick={() => setToDelete(amenity)}
                          aria-label={`Eliminar ${amenity.name}`}
                        >
                          <Trash2 className='size-4' />
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(amenity) => amenity.name}
              renderCardFields={(amenity) => [
                { label: 'Capacidad', value: String(amenity.capacity) },
                { label: 'Modo', value: amenity.reservation_mode?.name ?? '—' },
                {
                  label: 'Costo',
                  value:
                    amenity.has_cost && amenity.cost_amount != null
                      ? displayMoney(amenity.cost_amount)
                      : 'Sin costo',
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={amenity.out_of_service ? 'r' : 'g'}>
                      {amenity.out_of_service ? 'Fuera de servicio' : 'Disponible'}
                    </StatusPill>
                  ),
                },
              ]}
              onRowClick={
                canEdit ? (amenity) => navigate(`/amenity/${amenity.id}/edit`) : undefined
              }
              renderActions={
                canEdit || canDelete
                  ? (amenity) => (
                      <div className='flex justify-end gap-1.5'>
                        {canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => navigate(`/amenity/${amenity.id}/edit`)}
                            aria-label={`Editar ${amenity.name}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {canDelete && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => setToDelete(amenity)}
                            aria-label={`Eliminar ${amenity.name}`}
                          >
                            <Trash2 className='size-4' />
                          </Button>
                        )}
                      </div>
                    )
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ConfirmDialog
        isOpen={Boolean(toDelete)}
        variant='delete'
        entityName={toDelete?.name}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setToDelete(null)}
      />
    </div>
  )
}
