import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { CorrectionModal } from '@/modules/access/components/CorrectionModal'
import type { IAccessLog } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { useAccessLogStore } from '@/modules/access/store/accessStores'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
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
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type { Filter, ISearchParams } from '@/shared/interfaces/list.types'
import { Download, FilePenLine, Loader2 } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useShallow } from 'zustand/react/shallow'

type ShiftTab = 'day' | 'week' | 'month' | 'all'

/** Estado seleccionable para el filtro de bitácora. */
type AccessLogEstado = 'inside' | 'exited' | 'denied'

// ─── Filter state ─────────────────────────────────────────────────────────────

interface AccessLogFilters {
  unitIds: string[]
  plate: string
  entryTypeIds: string[]
  motiveIds: string[]
  estado: AccessLogEstado | null
}

const INITIAL_ACCESS_LOG_FILTERS: AccessLogFilters = {
  unitIds: [],
  plate: '',
  entryTypeIds: [],
  motiveIds: [],
  estado: null,
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Genera los filtros de rango de fecha para `entered_at` según la pestaña activa. */
function buildDateFilters(tab: ShiftTab): Filter[] {
  const now = new Date()

  const toUtc = (d: Date): string => d.toISOString().slice(0, 19).replace('T', ' ')
  const dayStart = (d: Date): Date =>
    new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0, 0)
  const dayEnd = (d: Date): Date =>
    new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59, 999)

  if (tab === 'day') {
    return [
      { field: 'entered_at', operator: '>=', value: toUtc(dayStart(now)) },
      { field: 'entered_at', operator: '<=', value: toUtc(dayEnd(now)) },
    ]
  }

  if (tab === 'week') {
    const dayOfWeek = now.getDay()
    const diff = dayOfWeek === 0 ? 6 : dayOfWeek - 1
    const monday = new Date(now)
    monday.setDate(now.getDate() - diff)
    const sunday = new Date(monday)
    sunday.setDate(monday.getDate() + 6)
    return [
      { field: 'entered_at', operator: '>=', value: toUtc(dayStart(monday)) },
      { field: 'entered_at', operator: '<=', value: toUtc(dayEnd(sunday)) },
    ]
  }

  if (tab === 'month') {
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0)
    return [
      { field: 'entered_at', operator: '>=', value: toUtc(dayStart(firstDay)) },
      { field: 'entered_at', operator: '<=', value: toUtc(dayEnd(lastDay)) },
    ]
  }

  return []
}

/**
 * Builds Orion-compatible filter array from the local AccessLogFilters state.
 * Does NOT include date-tab filters (entered_at) — those come from buildDateFilters().
 */
function buildAccessLogFilters(filters: AccessLogFilters): Filter[] {
  const result: Filter[] = []

  if (filters.unitIds.length > 0) {
    result.push({ field: 'unit_id', operator: 'in', value: filters.unitIds })
  }
  if (filters.plate.trim()) {
    result.push({ field: 'plate', operator: 'like', value: `%${filters.plate.trim()}%` })
  }
  if (filters.entryTypeIds.length > 0) {
    result.push({ field: 'access_entry_type_id', operator: 'in', value: filters.entryTypeIds })
  }
  if (filters.motiveIds.length > 0) {
    result.push({ field: 'visitor_motive_id', operator: 'in', value: filters.motiveIds })
  }
  // Estado: "Dentro" requiere DOS condiciones AND; "Salió"/"Denegado" requieren una.
  // Se usa Select simple (single-select) porque Orion no puede expresar un OR
  // entre campos distintos (exited_at IS NOT NULL OR denied_at IS NOT NULL) en una
  // sola solicitud de filtro.
  if (filters.estado === 'inside') {
    result.push({ field: 'exited_at', operator: 'is_null', value: null })
    result.push({ field: 'denied_at', operator: 'is_null', value: null })
  } else if (filters.estado === 'exited') {
    result.push({ field: 'exited_at', operator: 'is_not_null', value: null })
  } else if (filters.estado === 'denied') {
    result.push({ field: 'denied_at', operator: 'is_not_null', value: null })
  }

  return result
}

/**
 * Combina los filtros de fecha de la pestaña activa con los filtros del panel.
 * Es la ÚNICA fuente de verdad para la lista de filtros Orion de esta página.
 */
function buildCombinedFilters(tab: ShiftTab, panelFilters: AccessLogFilters): Filter[] {
  return [...buildDateFilters(tab), ...buildAccessLogFilters(panelFilters)]
}

/** Estado visual de un registro de bitácora (denegado / salió / dentro). */
function AccessLogStatus({ log }: { log: IAccessLog }) {
  if (log.denied_at) return <StatusPill tone='r'>Denegado</StatusPill>
  if (log.exited_at) return <StatusPill tone='b'>Salió</StatusPill>
  return <StatusPill tone='g'>Dentro</StatusPill>
}

/** Botón para anexar una corrección a un registro. */
function CorrectionButton({
  log,
  onCorrect,
}: {
  log: IAccessLog
  onCorrect: (_log: IAccessLog) => void
}) {
  return (
    <div className='flex items-center justify-end gap-2'>
      {log.correction && (
        <span
          className='max-w-[160px] truncate text-[11.5px] text-[var(--ink-4)]'
          title={log.correction}
        >
          {log.correction}
        </span>
      )}
      <Button
        size='sm'
        variant='outline'
        className='h-7 rounded-full px-2.5 text-[12px]'
        onClick={() => onCorrect(log)}
      >
        <FilePenLine className='size-3.5' />
        {log.correction ? 'Editar' : 'Corregir'}
      </Button>
    </div>
  )
}

/** Bitácora inmutable de accesos (§14.5): consulta con entrada/salida/denegados. */
export const AccessLogListPage = () => {
  const [activeTab, setActiveTab] = useState<ShiftTab>('all')
  const [filters, setFilters] = useState<AccessLogFilters>(INITIAL_ACCESS_LOG_FILTERS)

  const { onPageChanged, onSearch, onReport, isExporting, paramsSearch } = useBaseList<IAccessLog>({
    store: useAccessLogStore,
    resource: AccessLogResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        { relation: 'unit' },
        { relation: 'access_entry_type' },
        { relation: 'guard_user' },
        { relation: 'visitor_motive' },
      ],
    },
  })
  const { entities, pagination, isLoading } = useAccessLogStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const canCorrect = usePermissions('update.access_log')
  const [correcting, setCorrecting] = useState<IAccessLog | null>(null)

  // Catálogos de los filtros — LAZY y por catálogo. Cada uno con su propio hook:
  // abrir UN filtro dispara SÓLO su POST /selects (UNIT es el pesado). Nada se
  // carga al montar la lista; el costo se incurre recién al abrir cada dropdown.
  const {
    catalogs: unitCatalogs,
    enable: enableUnits,
    isLoading: loadingUnits,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.UNIT]: { _sort: 'number' },
      }),
      []
    )
  )
  const {
    catalogs: entryTypeCatalogs,
    enable: enableEntryTypes,
    isLoading: loadingEntryTypes,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.ACCESS_ENTRY_TYPE]: { _sort: 'name' },
      }),
      []
    )
  )
  const {
    catalogs: motiveCatalogs,
    enable: enableMotives,
    isLoading: loadingMotives,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.VISITOR_MOTIVE]: { _sort: 'name' },
      }),
      []
    )
  )

  const unitOptions = useMemo<MultiSelectOption[]>(
    () =>
      (unitCatalogs[CatalogType.UNIT] ?? []).map((u) => ({
        value: u.id,
        label:
          (u.full_identifier as string | undefined) ?? (u.number as string | undefined) ?? u.id,
      })),
    [unitCatalogs]
  )

  const entryTypeOptions = useMemo<MultiSelectOption[]>(
    () =>
      (entryTypeCatalogs[CatalogType.ACCESS_ENTRY_TYPE] ?? []).map((t) => ({
        value: t.id,
        label: (t.name as string | undefined) ?? t.id,
      })),
    [entryTypeCatalogs]
  )

  const motiveOptions = useMemo<MultiSelectOption[]>(
    () =>
      (motiveCatalogs[CatalogType.VISITOR_MOTIVE] ?? []).map((m) => ({
        value: m.id,
        label: (m.name as string | undefined) ?? m.id,
      })),
    [motiveCatalogs]
  )

  const { data: insideNow = [] } = useQuery({
    queryKey: ['access', 'now'],
    queryFn: () => AccessLogResource.now(),
    refetchInterval: 30_000,
    staleTime: 20_000,
  })

  const refresh = useCallback(() => onSearch({ ...paramsSearch }), [onSearch, paramsSearch])

  /**
   * Aplica filtros del panel. Reconstruye el array completo de filtros Orion
   * combinando la pestaña activa (entered_at) con el nuevo estado del panel.
   */
  const applyFilters = useCallback(
    (next: AccessLogFilters) => {
      setFilters(next)
      void onSearch({
        ...paramsSearch,
        page: 1,
        filters: buildCombinedFilters(activeTab, next),
      })
    },
    [onSearch, paramsSearch, activeTab]
  )

  /**
   * Cambia la pestaña de período. Reconstruye el array completo de filtros
   * combinando la nueva pestaña con el estado actual del panel.
   */
  const handleTabChange = useCallback(
    (tab: ShiftTab) => {
      setActiveTab(tab)
      void onSearch({
        ...paramsSearch,
        page: 1,
        filters: buildCombinedFilters(tab, filters),
      })
    },
    [onSearch, paramsSearch, filters]
  )

  /**
   * Limpia los filtros del panel. Mantiene la pestaña activa y su filtro de fecha.
   */
  const resetFilters = useCallback(() => {
    setFilters(INITIAL_ACCESS_LOG_FILTERS)
    void onSearch({
      sort: paramsSearch.sort,
      includes: paramsSearch.includes,
      page: 1,
      filters: buildDateFilters(activeTab),
    })
  }, [onSearch, paramsSearch, activeTab])

  const hasActiveFilters =
    filters.unitIds.length > 0 ||
    filters.plate.trim() !== '' ||
    filters.entryTypeIds.length > 0 ||
    filters.motiveIds.length > 0 ||
    filters.estado !== null

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Bitácora de Accesos'
        description='Registro inmutable de entradas y salidas del condominio'
        actions={
          <PermissionGuard permission='report.access_log'>
            <Button
              variant='outline'
              onClick={() => onReport('AccessLog', 'xlsx', 'bitacora-accesos')}
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
        }
      />

      {/* Tabs de período del turno */}
      <Tabs value={activeTab} onValueChange={(v) => handleTabChange(v as ShiftTab)}>
        <TabsList>
          <TabsTrigger value='day'>Día</TabsTrigger>
          <TabsTrigger value='week'>Semana</TabsTrigger>
          <TabsTrigger value='month'>Mes</TabsTrigger>
          <TabsTrigger value='all'>Todo</TabsTrigger>
        </TabsList>
      </Tabs>

      {/* Totales del turno */}
      <div className='flex flex-wrap gap-4 text-[13px] text-[var(--ink-2)]'>
        <span>
          Total ingresos: <span className='font-semibold text-foreground'>{pagination.total}</span>
        </span>
        <span>
          Aún dentro: <span className='font-semibold text-foreground'>{insideNow.length}</span>
        </span>
      </div>

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={(params: ISearchParams) => {
            // When SearchList triggers a text search, preserve the current combined filters
            void onSearch({
              ...params,
              filters: buildCombinedFilters(activeTab, filters),
              page: 1,
            })
          }}
          onReset={(_data) => resetFilters()}
          placeholder='Buscar en la bitácora…'
          defaultFiltersExpanded={false}
          toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
        >
          <div className='flex flex-wrap gap-2'>
            {/* El catálogo de cada filtro se carga al abrir el popover por primera
                vez (onFirstOpen → enable); la lista no paga /selects al montar. */}
            <MultiSelectFilter
              label='Unidad'
              options={unitOptions}
              value={filters.unitIds}
              onChange={(unitIds) => applyFilters({ ...filters, unitIds })}
              placeholder='Todas las unidades'
              compact
              onFirstOpen={enableUnits}
              isLoading={loadingUnits}
            />
            <div className='flex w-full flex-col gap-1 sm:w-48'>
              <span className='text-xs font-medium text-muted-foreground'>Placa</span>
              <Input
                type='text'
                placeholder='Ej. ABC-123'
                value={filters.plate}
                onChange={(e) => {
                  const next = { ...filters, plate: e.target.value }
                  setFilters(next)
                  // Apply on change (debounce not needed for controlled input)
                  void onSearch({
                    ...paramsSearch,
                    page: 1,
                    filters: buildCombinedFilters(activeTab, next),
                  })
                }}
                className='h-9 bg-card'
              />
            </div>
            <MultiSelectFilter
              label='Tipo de entrada'
              options={entryTypeOptions}
              value={filters.entryTypeIds}
              onChange={(entryTypeIds) => applyFilters({ ...filters, entryTypeIds })}
              placeholder='Todos los tipos'
              compact
              onFirstOpen={enableEntryTypes}
              isLoading={loadingEntryTypes}
            />
            <MultiSelectFilter
              label='Motivo'
              options={motiveOptions}
              value={filters.motiveIds}
              onChange={(motiveIds) => applyFilters({ ...filters, motiveIds })}
              placeholder='Todos los motivos'
              compact
              onFirstOpen={enableMotives}
              isLoading={loadingMotives}
            />
            {/* Estado: single-select porque "Dentro" requiere dos condiciones AND
                y Orion no puede expresar OR entre campos distintos en un solo filtro. */}
            <div className='flex w-full flex-col gap-1 sm:w-48'>
              <span className='text-xs font-medium text-muted-foreground'>Estado</span>
              <Select
                value={filters.estado ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ ...filters, estado: v === 'all' ? null : (v as AccessLogEstado) })
                }
              >
                <SelectTrigger className='h-9 w-full bg-card font-normal'>
                  <SelectValue placeholder='Todos los estados' />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value='all'>Todos</SelectItem>
                  <SelectItem value='inside'>Dentro</SelectItem>
                  <SelectItem value='exited'>Salió</SelectItem>
                  <SelectItem value='denied'>Denegado</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          {hasActiveFilters && (
            <div className='flex justify-end pt-1'>
              <Button
                type='button'
                variant='ghost'
                size='sm'
                className='text-[12px] text-muted-foreground hover:text-foreground'
                onClick={() => applyFilters(INITIAL_ACCESS_LOG_FILTERS)}
              >
                Limpiar filtros
              </Button>
            </div>
          )}
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState title='Bitácora vacía' />
        ) : (
          <>
            <ResponsiveDataTable<IAccessLog>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Visitante</TableHead>
                    <TableHead scope='col'>Unidad</TableHead>
                    <TableHead scope='col'>Tipo</TableHead>
                    <TableHead scope='col'>Entrada</TableHead>
                    <TableHead scope='col'>Salida</TableHead>
                    <TableHead scope='col'>Vigilante</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {canCorrect && (
                      <TableHead scope='col' className='text-right'>
                        Corrección
                      </TableHead>
                    )}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(log) => (
                <TableRow key={log.id}>
                  <TableCell className='font-medium'>
                    {log.visitor_name ?? '—'}
                    {log.plate && (
                      <span className='ml-2 rounded border border-border bg-[var(--surface-2)] px-1.5 font-mono text-[11px]'>
                        {log.plate}
                      </span>
                    )}
                  </TableCell>
                  <TableCell>{log.unit?.full_identifier ?? log.unit?.number ?? '—'}</TableCell>
                  <TableCell>{log.access_entry_type?.name ?? '—'}</TableCell>
                  <TableCell>{log.entered_at ? displayFormatDate(log.entered_at) : '—'}</TableCell>
                  <TableCell>{log.exited_at ? displayFormatDate(log.exited_at) : '—'}</TableCell>
                  <TableCell>{log.guard_user?.name ?? '—'}</TableCell>
                  <TableCell>
                    <AccessLogStatus log={log} />
                  </TableCell>
                  {canCorrect && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <CorrectionButton log={log} onCorrect={setCorrecting} />
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(log) => log.visitor_name ?? '—'}
              renderCardFields={(log) => [
                { label: 'Unidad', value: log.unit?.full_identifier ?? log.unit?.number ?? '—' },
                { label: 'Tipo', value: log.access_entry_type?.name ?? '—' },
                {
                  label: 'Entrada',
                  value: log.entered_at ? displayFormatDate(log.entered_at) : '—',
                },
                { label: 'Salida', value: log.exited_at ? displayFormatDate(log.exited_at) : '—' },
                { label: 'Vigilante', value: log.guard_user?.name ?? '—' },
                { label: 'Estado', value: <AccessLogStatus log={log} /> },
              ]}
              renderActions={
                canCorrect
                  ? (log) => <CorrectionButton log={log} onCorrect={setCorrecting} />
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <CorrectionModal log={correcting} onClose={() => setCorrecting(null)} onSaved={refresh} />
    </div>
  )
}
