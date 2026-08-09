import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  blacklistFormSchema,
  type BlacklistFormValues,
  type IBlacklistEntry,
} from '@/modules/access/schema'
import { BlacklistResource } from '@/modules/access/services/AccessResource'
import { useBlacklistStore } from '@/modules/access/store/accessStores'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import {
  CatalogFormModal,
  type CatalogCrudModuleProps,
  type CatalogFormProps,
  type CatalogResource,
} from '@/shared/components/catalog/CatalogCrudModule'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import {
  DateRangeFilter,
  resolveDateRange,
  type DateRange,
} from '@/shared/components/filters/DateRangeFilter'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type { Filter } from '@/shared/interfaces/list.types'
import { Download, Loader2, Lock, Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useShallow } from 'zustand/react/shallow'

// ─── Blacklist form config (stable — defined outside component) ────────────────

/** buildDefaults must be stable to avoid unnecessary form resets inside CatalogFormModal. */
function buildBlacklistDefaults(entity?: IBlacklistEntry | null): BlacklistFormValues {
  return {
    blacklist_type_id: entity?.blacklist_type_id ?? '',
    value: entity?.value ?? '',
    reason: entity?.reason ?? '',
    is_active: entity?.is_active ?? true,
  }
}

// ─── Filter state ─────────────────────────────────────────────────────────────

type BlacklistEstado = 'active' | 'inactive'

interface BlacklistFilters {
  typeIds: string[]
  estado: BlacklistEstado | null
  createdAt: DateRange
}

const EMPTY_DATE_RANGE: DateRange = { from: null, to: null, shortcut: null }

const INITIAL_BLACKLIST_FILTERS: BlacklistFilters = {
  typeIds: [],
  estado: null,
  createdAt: EMPTY_DATE_RANGE,
}

function buildBlacklistFilters(filters: BlacklistFilters): Filter[] {
  const result: Filter[] = []

  if (filters.typeIds.length > 0) {
    result.push({ field: 'blacklist_type_id', operator: 'in', value: filters.typeIds })
  }
  if (filters.estado === 'active') {
    result.push({ field: 'is_active', operator: '=', value: true })
  } else if (filters.estado === 'inactive') {
    result.push({ field: 'is_active', operator: '=', value: false })
  }

  const createdResolved = resolveDateRange(filters.createdAt)
  if (createdResolved.from) {
    result.push({ field: 'created_at', operator: '>=', value: createdResolved.from })
  }
  if (createdResolved.to) {
    // created_at es timestamp: el bound "hasta" pelado (YYYY-MM-DD) excluye ese
    // mismo día. Extendemos a fin de día para incluirlo.
    result.push({ field: 'created_at', operator: '<=', value: `${createdResolved.to} 23:59:59` })
  }

  return result
}

// ─── Page ─────────────────────────────────────────────────────────────────────

/**
 * Lista Negra — refactorizada como página custom para soportar filtros colapsables.
 * Reutiliza CatalogFormModal (exportado de CatalogCrudModule) para el alta/edición.
 *
 * Decisión de diseño: se optó por refactorizar en vez de agregar props a CatalogCrudModule
 * para mantener ese componente genérico sin lógica de filtros acoplada. CatalogFormModal
 * se exporta para ser reusado aquí sin duplicar el formulario de Sheet+Fields.
 */
export const BlacklistListPage = () => {
  const [filters, setFilters] = useState<BlacklistFilters>(INITIAL_BLACKLIST_FILTERS)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntry, setSelectedEntry] = useState<IBlacklistEntry | null>(null)
  const [entryToDelete, setEntryToDelete] = useState<IBlacklistEntry | null>(null)

  const { onPageChanged, onRemove, onSearch, onReport, isExporting, paramsSearch } =
    useBaseList<IBlacklistEntry>({
      store: useBlacklistStore,
      resource: BlacklistResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'created_at', direction: 'desc' }],
        includes: [{ relation: 'blacklist_type' }],
      },
    })
  const { entities, pagination, isLoading, isDeleting } = useBlacklistStore(
    useShallow((s) => ({
      entities: s.entities,
      pagination: s.pagination,
      isLoading: s.isLoading,
      isDeleting: s.isDeleting,
    }))
  )

  const permissions = useMultiplePermissions({
    canCreate: 'create.blacklist_entry',
    canEdit: 'update.blacklist_entry',
    canDelete: 'delete.blacklist_entry',
  })

  // Catalogs for filter dropdowns. LAZY: no se dispara POST /selects al montar la
  // lista; sólo cuando el usuario abre el filtro de tipo (enable() idempotente).
  const {
    catalogs,
    enable: enableTypes,
    isLoading: loadingTypes,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.BLACKLIST_TYPE]: { _sort: 'name' },
      }),
      []
    )
  )

  const typeOptions = useMemo<MultiSelectOption[]>(
    () =>
      (catalogs[CatalogType.BLACKLIST_TYPE] ?? []).map((t) => ({
        value: t.id,
        label: t.name ?? t.id,
      })),
    [catalogs]
  )

  /**
   * Aplica filtros del panel y dispara una búsqueda completa.
   */
  const applyFilters = useCallback(
    (next: BlacklistFilters) => {
      setFilters(next)
      void onSearch({
        ...paramsSearch,
        page: 1,
        filters: buildBlacklistFilters(next),
      })
    },
    [onSearch, paramsSearch]
  )

  const resetFilters = useCallback(() => {
    setFilters(INITIAL_BLACKLIST_FILTERS)
    void onSearch({
      sort: paramsSearch.sort,
      includes: paramsSearch.includes,
      page: 1,
    })
  }, [onSearch, paramsSearch])

  const hasActiveFilters =
    filters.typeIds.length > 0 ||
    filters.estado !== null ||
    filters.createdAt.from !== null ||
    filters.createdAt.to !== null ||
    filters.createdAt.shortcut !== null

  // Stable config object for CatalogFormModal (memoized to avoid form resets)
  // CatalogFormModal is typed against CatalogCrudModuleProps; BlacklistResource
  // satisfies CatalogResource<IBlacklistEntry, BlacklistFormValues> via structural
  // subtyping (Record<string,unknown> is a supertype of BlacklistFormValues, so
  // the create/update functions accept any BlacklistFormValues input).
  const formConfig = useMemo<CatalogCrudModuleProps<IBlacklistEntry, BlacklistFormValues>>(
    () => ({
      title: 'Lista Negra',
      description: 'Personas y placas con acceso restringido al condominio',
      newLabel: 'Nueva entrada',
      editLabel: 'Editar entrada',
      searchPlaceholder: 'Buscar por nombre o placa…',
      permissionEntity: 'blacklist_entry',
      store: useBlacklistStore,
      // createCrudApi tipa V como Record<string,unknown>; por contravarianza no es
      // asignable directo a CatalogResource<_, BlacklistFormValues>. Doble cast vía
      // unknown (sin `any`, respeta la regla del proyecto).
      resource: BlacklistResource as unknown as CatalogResource<
        IBlacklistEntry,
        BlacklistFormValues
      >,
      schema: blacklistFormSchema,
      nameLabel: 'Valor',
      renderName: (entry) => entry.value,
      buildDefaults: buildBlacklistDefaults,
      fields: [
        {
          name: 'blacklist_type_id',
          label: 'Tipo',
          type: 'catalog',
          catalog: CatalogType.BLACKLIST_TYPE,
          required: true,
        },
        { name: 'value', label: 'Valor (nombre o placa)', required: true },
        { name: 'reason', label: 'Motivo', type: 'textarea' },
        { name: 'is_active', label: 'Activa', type: 'switch', colSpan: 6 },
      ],
      initialSearch: {
        sort: [{ field: 'created_at', direction: 'desc' }],
        includes: [{ relation: 'blacklist_type' }],
      },
    }),
    []
  )

  const formProps = useMemo<CatalogFormProps<IBlacklistEntry, BlacklistFormValues>>(
    () => ({
      config: formConfig,
      mode: modalMode,
      entity: selectedEntry,
      isOpen: modalOpen,
      onClose: () => {
        setModalOpen(false)
        setSelectedEntry(null)
        void onSearch({ ...paramsSearch })
      },
    }),
    [formConfig, modalMode, selectedEntry, modalOpen, onSearch, paramsSearch]
  )

  const handleCreate = useCallback(() => {
    setSelectedEntry(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((entry: IBlacklistEntry) => {
    setSelectedEntry(entry)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteConfirm = useCallback(async () => {
    if (!entryToDelete) return
    try {
      await onRemove(entryToDelete)
      setEntryToDelete(null)
    } catch {
      // el toast global ya reporta el error
    }
  }, [entryToDelete, onRemove])

  const showActions = permissions.canEdit || permissions.canDelete

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Lista Negra'
        description='Personas y placas con acceso restringido al condominio'
        actions={
          <>
            <PermissionGuard permission='report.blacklist_entry'>
              <Button
                variant='outline'
                onClick={() => onReport('BlacklistEntry', 'xlsx', 'lista-negra')}
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
            <PermissionGuard permission='create.blacklist_entry'>
              <Button onClick={handleCreate} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                Nueva entrada
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={(params) => {
            void onSearch({
              ...params,
              filters: buildBlacklistFilters(filters),
              page: 1,
            })
          }}
          onReset={(_data) => resetFilters()}
          placeholder='Buscar por nombre o placa…'
          defaultFiltersExpanded={false}
          toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
        >
          <div className='flex flex-wrap gap-2'>
            {/* El catálogo se carga al abrir el popover por primera vez
                (onFirstOpen → enable); la lista no paga /selects al montar. */}
            <MultiSelectFilter
              label='Tipo'
              options={typeOptions}
              value={filters.typeIds}
              onChange={(typeIds) => applyFilters({ ...filters, typeIds })}
              placeholder='Todos los tipos'
              compact
              onFirstOpen={enableTypes}
              isLoading={loadingTypes}
            />
            {/* Estado: single-select (booleano — una entrada no puede ser
                simultáneamente Vigente e Inactiva) */}
            <div className='flex w-full flex-col gap-1 sm:w-48'>
              <span className='text-xs font-medium text-muted-foreground'>Estado</span>
              <Select
                value={filters.estado ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ ...filters, estado: v === 'all' ? null : (v as BlacklistEstado) })
                }
              >
                <SelectTrigger className='h-9 w-full bg-card font-normal'>
                  <SelectValue placeholder='Todos' />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value='all'>Todos</SelectItem>
                  <SelectItem value='active'>Vigente</SelectItem>
                  <SelectItem value='inactive'>Inactiva</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <DateRangeFilter
              label='Fecha de creación'
              value={filters.createdAt}
              onChange={(createdAt) => applyFilters({ ...filters, createdAt })}
              shortcuts={['today', 'this_week', 'this_month']}
            />
          </div>
          {hasActiveFilters && (
            <div className='flex justify-end pt-1'>
              <Button
                type='button'
                variant='ghost'
                size='sm'
                className='text-[12px] text-muted-foreground hover:text-foreground'
                onClick={() => applyFilters(INITIAL_BLACKLIST_FILTERS)}
              >
                Limpiar filtros
              </Button>
            </div>
          )}
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IBlacklistEntry>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Valor</TableHead>
                    <TableHead scope='col'>Tipo</TableHead>
                    <TableHead scope='col'>Motivo</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Fecha de creación
                    </TableHead>
                    {showActions && <TableHead scope='col' className='text-right' />}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(entry) => (
                <TableRow key={entry.id}>
                  <TableCell className='font-medium'>
                    <span className='inline-flex items-center gap-1.5'>
                      {entry.value}
                      {(entry as { is_system?: boolean }).is_system && (
                        <Lock
                          className='size-3.5 text-[var(--ink-4)]'
                          aria-label='Registro de sistema: no puede eliminarse'
                        />
                      )}
                    </span>
                  </TableCell>
                  <TableCell>{entry.blacklist_type?.name ?? '—'}</TableCell>
                  <TableCell>{entry.reason ?? '—'}</TableCell>
                  <TableCell>
                    <StatusPill tone={entry.is_active ? 'r' : 'b'}>
                      {entry.is_active ? 'Vigente' : 'Inactiva'}
                    </StatusPill>
                  </TableCell>
                  <TableCell className='hidden md:table-cell'>
                    {displayFormatDate(entry.created_at)}
                  </TableCell>
                  {showActions && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <div className='flex justify-end gap-1'>
                        {permissions.canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => handleEdit(entry)}
                            aria-label={`Editar ${entry.value}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {permissions.canDelete && !(entry as { is_system?: boolean }).is_system && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8 text-destructive hover:text-destructive'
                            onClick={() => setEntryToDelete(entry)}
                            aria-label={`Eliminar ${entry.value}`}
                          >
                            <Trash2 className='size-4' />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(entry) => entry.value}
              renderCardFields={(entry) => [
                { label: 'Tipo', value: entry.blacklist_type?.name ?? '—' },
                { label: 'Motivo', value: entry.reason ?? '—' },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={entry.is_active ? 'r' : 'b'}>
                      {entry.is_active ? 'Vigente' : 'Inactiva'}
                    </StatusPill>
                  ),
                },
                { label: 'Fecha de creación', value: displayFormatDate(entry.created_at) },
              ]}
              renderActions={
                showActions
                  ? (entry) => (
                      <div className='flex justify-end gap-1'>
                        {permissions.canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => handleEdit(entry)}
                            aria-label={`Editar ${entry.value}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {permissions.canDelete && !(entry as { is_system?: boolean }).is_system && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8 text-destructive hover:text-destructive'
                            onClick={() => setEntryToDelete(entry)}
                            aria-label={`Eliminar ${entry.value}`}
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

      <CatalogFormModal {...formProps} />

      <ConfirmDialog
        isOpen={Boolean(entryToDelete)}
        variant='delete'
        entityName={entryToDelete ? entryToDelete.value : undefined}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setEntryToDelete(null)}
      />
    </div>
  )
}
