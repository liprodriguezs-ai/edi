import { Button } from '@/components/ui/button'
import { CatalogPill } from '@/shared/components/CatalogPill'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { UnitFormModal } from '@/modules/unit/UnitFormModal'
import { useUnitList } from '@/modules/unit/hooks/useUnitList'
import type { IUnit } from '@/modules/unit/schema'
import { useUnitStore } from '@/modules/unit/store/unitStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import type { Filter } from '@/shared/interfaces/list.types'
import { Download, Loader2, Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const UNIT_PERMISSIONS = {
  canCreate: 'create.unit',
  canEdit: 'update.unit',
  canDelete: 'delete.unit',
} as const

export const UnitListPage = () => {
  const { onPageChanged, onRemove, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useUnitList()
  const { entities, pagination, isLoading } = useUnitStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = useUnitStore((s) => s.isDeleting)
  const permissions = useMultiplePermissions(UNIT_PERMISSIONS)
  const navigate = useNavigate()

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<IUnit | null>(null)
  const [entityToDelete, setEntityToDelete] = useState<IUnit | null>(null)
  const [typeIds, setTypeIds] = useState<string[]>([])
  const [statusIds, setStatusIds] = useState<string[]>([])
  const [towerIds, setTowerIds] = useState<string[]>([])

  // Cada filtro tiene su propio catálogo LAZY: abrir un dropdown dispara SOLO
  // su /selects (no se carga nada al montar la lista). Máximo ahorro de infra.
  const typeCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.UNIT_TYPE]: { _sort: 'order' } }), [])
  )
  const statusCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.UNIT_STATUS]: { _sort: 'order' } }), [])
  )
  const towerCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.TOWER]: { _sort: 'order' } }), [])
  )

  const typeOptions = useMemo<MultiSelectOption[]>(
    () =>
      (typeCatalog.catalogs[CatalogType.UNIT_TYPE] ?? []).map((t) => ({
        value: t.id,
        label: t.name,
      })),
    [typeCatalog.catalogs]
  )
  const statusOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusCatalog.catalogs[CatalogType.UNIT_STATUS] ?? []).map((s) => ({
        value: s.id,
        label: s.name,
      })),
    [statusCatalog.catalogs]
  )
  const towerOptions = useMemo<MultiSelectOption[]>(
    () =>
      (towerCatalog.catalogs[CatalogType.TOWER] ?? []).map((t) => ({ value: t.id, label: t.name })),
    [towerCatalog.catalogs]
  )

  // Construye los filtros Orion `in` (mismo patrón que buildChargeFilters /
  // buildPaymentFilters): cada array NO vacío → un único clause `in`; array vacío → se omite.
  const applyFilters = useCallback(
    (types: string[], statuses: string[], towers: string[]) => {
      const filters: Filter[] = []
      if (types.length > 0) filters.push({ field: 'unit_type_id', operator: 'in', value: types })
      if (statuses.length > 0)
        filters.push({ field: 'unit_status_id', operator: 'in', value: statuses })
      if (towers.length > 0) filters.push({ field: 'tower_id', operator: 'in', value: towers })
      onSearch({ ...paramsSearch, page: 1, filters })
    },
    [onSearch, paramsSearch]
  )

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((unit: IUnit) => {
    setSelectedEntity(unit)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteConfirm = useCallback(async () => {
    if (!entityToDelete) return
    try {
      await onRemove(entityToDelete)
      setEntityToDelete(null)
    } catch {
      // el toast global reporta el error
    }
  }, [entityToDelete, onRemove])

  const handleCreated = useCallback(
    (unit: IUnit) => {
      // §6.3.3: tras crear, ir al detalle para asociar personas
      if (modalMode === CrudMode.CREATE) {
        navigate(`/unit/${unit.id}`)
      }
    },
    [modalMode, navigate]
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Unidades'
        description='Viviendas y locales del condominio'
        actions={
          <>
            <PermissionGuard permission='report.unit'>
              <Button
                variant='outline'
                onClick={() => onReport('Unit', 'xlsx', 'unidades')}
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
            <PermissionGuard permission='create.unit'>
              <Button onClick={handleCreate} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                Nueva unidad
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={() => {
            setTypeIds([])
            setStatusIds([])
            setTowerIds([])
            onReset()
          }}
          placeholder='Buscar por número, torre o sección…'
        >
          <div className='flex flex-wrap gap-2'>
            <MultiSelectFilter
              label='Tipo de unidad'
              options={typeOptions}
              value={typeIds}
              onChange={(next) => {
                setTypeIds(next)
                applyFilters(next, statusIds, towerIds)
              }}
              placeholder='Todos los tipos'
              compact
              onFirstOpen={typeCatalog.enable}
              isLoading={typeCatalog.isLoading}
            />
            <MultiSelectFilter
              label='Estado'
              options={statusOptions}
              value={statusIds}
              onChange={(next) => {
                setStatusIds(next)
                applyFilters(typeIds, next, towerIds)
              }}
              placeholder='Todos los estados'
              compact
              onFirstOpen={statusCatalog.enable}
              isLoading={statusCatalog.isLoading}
            />
            <MultiSelectFilter
              label='Torre'
              options={towerOptions}
              value={towerIds}
              onChange={(next) => {
                setTowerIds(next)
                applyFilters(typeIds, statusIds, next)
              }}
              placeholder='Todas las torres'
              compact
              onFirstOpen={towerCatalog.enable}
              isLoading={towerCatalog.isLoading}
            />
          </div>
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Aún no hay unidades registradas'
            description='Comienza creando la primera.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IUnit>
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Identificador</TableHead>
                    <TableHead scope='col'>Tipo</TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Sección
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='text-right'></TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(unit) => (
                <TableRow
                  key={unit.id}
                  className='cursor-pointer'
                  onClick={() => navigate(`/unit/${unit.id}`)}
                >
                  <TableCell className='font-medium'>
                    {unit.full_identifier ?? unit.number}
                  </TableCell>
                  <TableCell>{unit.unit_type?.name ?? '—'}</TableCell>
                  <TableCell className='hidden md:table-cell'>
                    {unit.section?.name ?? '—'}
                  </TableCell>
                  <TableCell>
                    <CatalogPill name={unit.unit_status?.name} color={unit.unit_status?.color} />
                  </TableCell>
                  <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                    <div className='flex justify-end gap-1'>
                      {permissions.canEdit && (
                        <Button
                          variant='ghost'
                          size='icon'
                          className='size-8'
                          onClick={() => handleEdit(unit)}
                          aria-label={`Editar ${unit.full_identifier ?? ''}`}
                        >
                          <Pencil className='size-4' />
                        </Button>
                      )}
                      {permissions.canDelete && (
                        <Button
                          variant='ghost'
                          size='icon'
                          className='size-8 text-destructive hover:text-destructive'
                          onClick={() => setEntityToDelete(unit)}
                          aria-label={`Eliminar ${unit.full_identifier ?? ''}`}
                        >
                          <Trash2 className='size-4' />
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              )}
              onRowClick={(unit) => navigate(`/unit/${unit.id}`)}
              renderCardTitle={(unit) => unit.full_identifier ?? unit.number}
              renderCardFields={(unit) => [
                { label: 'Tipo', value: unit.unit_type?.name ?? '—' },
                { label: 'Sección', value: unit.section?.name ?? '—' },
                {
                  label: 'Estado',
                  value: (
                    <CatalogPill name={unit.unit_status?.name} color={unit.unit_status?.color} />
                  ),
                },
              ]}
              renderActions={(unit) => (
                <div className='flex justify-end gap-1'>
                  {permissions.canEdit && (
                    <Button
                      variant='ghost'
                      size='icon'
                      className='size-8'
                      onClick={() => handleEdit(unit)}
                      aria-label={`Editar ${unit.full_identifier ?? ''}`}
                    >
                      <Pencil className='size-4' />
                    </Button>
                  )}
                  {permissions.canDelete && (
                    <Button
                      variant='ghost'
                      size='icon'
                      className='size-8 text-destructive hover:text-destructive'
                      onClick={() => setEntityToDelete(unit)}
                      aria-label={`Eliminar ${unit.full_identifier ?? ''}`}
                    >
                      <Trash2 className='size-4' />
                    </Button>
                  )}
                </div>
              )}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <UnitFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={selectedEntity ?? undefined}
        onClose={() => {
          setModalOpen(false)
          setSelectedEntity(null)
        }}
        onSuccess={handleCreated}
      />

      <ConfirmDialog
        isOpen={Boolean(entityToDelete)}
        variant='delete'
        entityName={entityToDelete?.full_identifier}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setEntityToDelete(null)}
      />
    </div>
  )
}
