import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { VISITOR_STATUS, type IVisitor } from '@/modules/access/schema'
import { VisitorResource } from '@/modules/access/services/AccessResource'
import { useVisitorStore } from '@/modules/access/store/accessStores'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Pagination } from '@/shared/components/Pagination'
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
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type { Filter, ISearchParams } from '@/shared/interfaces/list.types'
import { VisitorFormModal } from '@/modules/access/components/VisitorFormModal'
import { VisitorQrModal } from '@/modules/access/components/VisitorQrModal'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useMutation } from '@tanstack/react-query'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Ban, Check, Download, Loader2, Plus, QrCode, X } from 'lucide-react'
import { useCallback, useMemo, useRef, useState } from 'react'
import { useShallow } from 'zustand/react/shallow'

// ─── Filter state ─────────────────────────────────────────────────────────────

type ArrivesBy = 'all' | 'vehicle' | 'on_foot'

interface VisitorFilters {
  unitIds: string[]
  plate: string
  motiveIds: string[]
  qrTypeIds: string[]
  arrivesBy: ArrivesBy
  qrExpiresAt: DateRange
}

const EMPTY_DATE_RANGE: DateRange = { from: null, to: null, shortcut: null }

const INITIAL_VISITOR_FILTERS: VisitorFilters = {
  unitIds: [],
  plate: '',
  motiveIds: [],
  qrTypeIds: [],
  arrivesBy: 'all',
  qrExpiresAt: EMPTY_DATE_RANGE,
}

// ─── Tab status constants ─────────────────────────────────────────────────────

type VisitorTab = 'upcoming' | 'history'

const UPCOMING_STATUSES = [
  VISITOR_STATUS.PENDING,
  VISITOR_STATUS.APPROVED,
  VISITOR_STATUS.PENDING_APPROVAL,
]

const HISTORY_STATUSES = [
  VISITOR_STATUS.USED,
  VISITOR_STATUS.EXPIRED,
  VISITOR_STATUS.CANCELLED,
  VISITOR_STATUS.REJECTED,
]

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Genera el filtro visitor_status_id para la pestaña activa. */
function buildStatusFilter(tab: VisitorTab): Filter[] {
  return [
    {
      field: 'visitor_status_id',
      operator: 'in',
      value: tab === 'upcoming' ? UPCOMING_STATUSES : HISTORY_STATUSES,
    },
  ]
}

/**
 * Builds Orion-compatible filter array from the local VisitorFilters state.
 * Does NOT include the visitor_status_id filter (that comes from the tab).
 */
function buildVisitorFilters(filters: VisitorFilters): Filter[] {
  const result: Filter[] = []

  if (filters.unitIds.length > 0) {
    result.push({ field: 'unit_id', operator: 'in', value: filters.unitIds })
  }
  if (filters.plate.trim()) {
    result.push({ field: 'plate', operator: 'like', value: `%${filters.plate.trim()}%` })
  }
  if (filters.motiveIds.length > 0) {
    result.push({ field: 'visitor_motive_id', operator: 'in', value: filters.motiveIds })
  }
  if (filters.qrTypeIds.length > 0) {
    result.push({ field: 'qr_type_id', operator: 'in', value: filters.qrTypeIds })
  }
  if (filters.arrivesBy === 'vehicle') {
    result.push({ field: 'arrives_by_vehicle', operator: '=', value: true })
  } else if (filters.arrivesBy === 'on_foot') {
    result.push({ field: 'arrives_by_vehicle', operator: '=', value: false })
  }

  const expiresResolved = resolveDateRange(filters.qrExpiresAt)
  if (expiresResolved.from) {
    result.push({ field: 'qr_expires_at', operator: '>=', value: expiresResolved.from })
  }
  if (expiresResolved.to) {
    // qr_expires_at es timestamp: el bound "hasta" pelado (YYYY-MM-DD) excluye ese
    // mismo día. Extendemos a fin de día para incluirlo.
    result.push({ field: 'qr_expires_at', operator: '<=', value: `${expiresResolved.to} 23:59:59` })
  }

  return result
}

/**
 * Combina el filtro de pestaña (visitor_status_id) con los filtros del panel.
 * Es la ÚNICA fuente de verdad para la lista de filtros Orion de esta página.
 */
function buildCombinedFilters(tab: VisitorTab, panelFilters: VisitorFilters): Filter[] {
  return [...buildStatusFilter(tab), ...buildVisitorFilters(panelFilters)]
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function VisitorActions({
  visitor,
  isPending,
  onShowQr,
  onCancel,
  onApprove,
  onReject,
}: {
  visitor: IVisitor
  isPending: boolean
  onShowQr: (_v: IVisitor) => void
  onCancel: (_v: IVisitor) => void
  onApprove: (_v: IVisitor) => void
  onReject: (_v: IVisitor) => void
}) {
  const canApprove = visitor.visitor_status_id === VISITOR_STATUS.PENDING_APPROVAL

  return (
    <div className='flex justify-end gap-1.5'>
      {visitor.qr_token && (
        <Button
          size='sm'
          variant='outline'
          className='h-7 rounded-full px-2.5 text-[12px]'
          onClick={() => onShowQr(visitor)}
          aria-label={`Ver QR de ${visitor.name}`}
        >
          <QrCode className='size-3.5' />
          Ver QR
        </Button>
      )}
      {visitor.qr_token && visitor.visitor_status_id === VISITOR_STATUS.PENDING && (
        <Button
          size='sm'
          variant='outline'
          className='h-7 rounded-full px-2.5 text-[12px] text-destructive hover:text-destructive'
          onClick={() => onCancel(visitor)}
          disabled={isPending}
          aria-label={`Cancelar QR de ${visitor.name}`}
        >
          <Ban className='size-3.5' />
          Cancelar QR
        </Button>
      )}
      {canApprove && (
        <>
          <Button
            size='sm'
            className='h-7 rounded-full px-2.5 text-[12px]'
            onClick={() => onApprove(visitor)}
            disabled={isPending}
            aria-label={`Aprobar entrada de ${visitor.name}`}
          >
            <Check className='size-3.5' />
            Aprobar
          </Button>
          <Button
            size='sm'
            variant='outline'
            className='h-7 rounded-full px-2.5 text-[12px] text-destructive hover:text-destructive'
            onClick={() => onReject(visitor)}
            disabled={isPending}
            aria-label={`Rechazar entrada de ${visitor.name}`}
          >
            <X className='size-3.5' />
            Rechazar
          </Button>
        </>
      )}
    </div>
  )
}

function VehicleValue({ visitor }: { visitor: IVisitor }) {
  if (!visitor.arrives_by_vehicle) return <>A pie</>
  return (
    <span className='rounded border border-border bg-[var(--surface-2)] px-1.5 font-mono text-[12px]'>
      {visitor.plate ?? 's/placa'}
    </span>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

/** Visitantes registrados (§14): QRs vigentes y solicitudes con aprobar/rechazar/cancelar. */
export const VisitorListPage = () => {
  const [activeTab, setActiveTab] = useState<VisitorTab>('upcoming')
  const [filters, setFilters] = useState<VisitorFilters>(INITIAL_VISITOR_FILTERS)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [qrVisitor, setQrVisitor] = useState<IVisitor | null>(null)
  const [cancelVisitor, setCancelVisitor] = useState<IVisitor | null>(null)

  const { onPageChanged, onSearch, onReport, isExporting, paramsSearch } = useBaseList<IVisitor>({
    store: useVisitorStore,
    resource: VisitorResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        { relation: 'unit' },
        { relation: 'visitor_motive' },
        { relation: 'visitor_status' },
        { relation: 'created_by_user' },
      ],
      filters: buildStatusFilter('upcoming'),
    },
  })
  const { entities, pagination, isLoading } = useVisitorStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  // Catálogos de los filtros — LAZY y por catálogo. Cada uno tiene su propio hook:
  // así abrir UN filtro dispara SÓLO su POST /selects (UNIT es el pesado). Nada se
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
  const {
    catalogs: qrTypeCatalogs,
    enable: enableQrTypes,
    isLoading: loadingQrTypes,
  } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.QR_TYPE]: { _sort: 'name' },
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

  const motiveOptions = useMemo<MultiSelectOption[]>(
    () =>
      (motiveCatalogs[CatalogType.VISITOR_MOTIVE] ?? []).map((m) => ({
        value: m.id,
        label: (m.name as string | undefined) ?? m.id,
      })),
    [motiveCatalogs]
  )

  const qrTypeOptions = useMemo<MultiSelectOption[]>(
    () =>
      (qrTypeCatalogs[CatalogType.QR_TYPE] ?? []).map((t) => ({
        value: t.id,
        label: (t.name as string | undefined) ?? t.id,
      })),
    [qrTypeCatalogs]
  )

  const actionMutation = useMutation({
    mutationFn: ({
      visitor,
      action,
    }: {
      visitor: IVisitor
      action: 'approve' | 'reject' | 'cancel'
    }) => {
      if (action === 'approve') return VisitorResource.approve(visitor.id)
      if (action === 'reject') return VisitorResource.reject(visitor.id)
      return VisitorResource.cancelQr(visitor.id)
    },
    onSuccess: (_, { action }) => {
      toast.success(
        action === 'approve'
          ? 'Entrada aprobada'
          : action === 'reject'
            ? 'Entrada rechazada'
            : 'QR cancelado'
      )
      void onSearch({ ...paramsSearch })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo completar la acción'
      ),
  })

  const handleApprove = useCallback(
    (visitor: IVisitor) => actionMutation.mutate({ visitor, action: 'approve' }),
    [actionMutation]
  )
  const handleReject = useCallback(
    (visitor: IVisitor) => actionMutation.mutate({ visitor, action: 'reject' }),
    [actionMutation]
  )

  /**
   * Aplica filtros del panel. Reconstruye el array completo de filtros Orion
   * combinando la pestaña activa (visitor_status_id) con el nuevo estado del panel.
   */
  const applyFilters = useCallback(
    (next: VisitorFilters) => {
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
   * Cambia la pestaña de estado. Reconstruye el array completo de filtros
   * combinando la nueva pestaña con el estado actual del panel.
   */
  const handleTabChange = useCallback(
    (tab: VisitorTab) => {
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
   * Limpia los filtros del panel. Mantiene la pestaña activa y su filtro de estado.
   */
  const resetFilters = useCallback(() => {
    setFilters(INITIAL_VISITOR_FILTERS)
    void onSearch({
      sort: paramsSearch.sort,
      includes: paramsSearch.includes,
      page: 1,
      filters: buildStatusFilter(activeTab),
    })
  }, [onSearch, paramsSearch, activeTab])

  /**
   * Actualiza el filtro de placa. El input controlado refleja el valor de
   * inmediato, pero la búsqueda al backend se difiere ~350ms para no disparar
   * una request por cada tecla (mismo criterio que el debounce de SearchList).
   */
  const plateSearchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null)
  const handlePlateChange = useCallback(
    (value: string) => {
      const next = { ...filters, plate: value }
      setFilters(next)
      if (plateSearchTimeout.current) clearTimeout(plateSearchTimeout.current)
      plateSearchTimeout.current = setTimeout(() => {
        void onSearch({
          ...paramsSearch,
          page: 1,
          filters: buildCombinedFilters(activeTab, next),
        })
      }, 350)
    },
    [filters, onSearch, paramsSearch, activeTab]
  )

  const hasActiveFilters =
    filters.unitIds.length > 0 ||
    filters.plate.trim() !== '' ||
    filters.motiveIds.length > 0 ||
    filters.qrTypeIds.length > 0 ||
    filters.arrivesBy !== 'all' ||
    filters.qrExpiresAt.from !== null ||
    filters.qrExpiresAt.to !== null ||
    filters.qrExpiresAt.shortcut !== null

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Visitantes'
        description='Visitas registradas con QR y solicitudes de entrada'
        actions={
          <>
            <PermissionGuard permission='report.visitor'>
              <Button
                variant='outline'
                onClick={() => onReport('Visitor', 'xlsx', 'visitantes')}
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
            <PermissionGuard permission='create.visitor'>
              <Button onClick={() => setIsFormOpen(true)} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                Registrar visita
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <VisitorFormModal
        isOpen={isFormOpen}
        onClose={() => setIsFormOpen(false)}
        onCreated={(visitor) => {
          setQrVisitor(visitor)
          void onSearch({ ...paramsSearch })
        }}
      />
      <VisitorQrModal
        visitor={qrVisitor}
        onClose={() => setQrVisitor(null)}
        onCancel={setCancelVisitor}
      />

      <ConfirmDialog
        isOpen={Boolean(cancelVisitor)}
        variant='delete'
        title='Cancelar QR de visita'
        description={`El QR de ${cancelVisitor?.name ?? 'la visita'} dejará de ser válido en la caseta. Esta acción no se puede deshacer.`}
        confirmLabel='Cancelar QR'
        isLoading={actionMutation.isPending}
        onConfirm={() => {
          if (cancelVisitor) actionMutation.mutate({ visitor: cancelVisitor, action: 'cancel' })
          setCancelVisitor(null)
        }}
        onCancel={() => setCancelVisitor(null)}
      />

      {/* Tabs Próximas / Histórico */}
      <Tabs value={activeTab} onValueChange={(v) => handleTabChange(v as VisitorTab)}>
        <TabsList>
          <TabsTrigger value='upcoming'>Próximas</TabsTrigger>
          <TabsTrigger value='history'>Histórico</TabsTrigger>
        </TabsList>
      </Tabs>

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={(params: ISearchParams) => {
            // When SearchList triggers a text search, preserve the combined filters
            void onSearch({
              ...params,
              filters: buildCombinedFilters(activeTab, filters),
              page: 1,
            })
          }}
          onReset={(_data) => resetFilters()}
          placeholder='Buscar por nombre o placa…'
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
                onChange={(e) => handlePlateChange(e.target.value)}
                className='h-9 bg-card'
              />
            </div>
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
            <MultiSelectFilter
              label='Tipo de QR'
              options={qrTypeOptions}
              value={filters.qrTypeIds}
              onChange={(qrTypeIds) => applyFilters({ ...filters, qrTypeIds })}
              placeholder='Todos los tipos'
              compact
              onFirstOpen={enableQrTypes}
              isLoading={loadingQrTypes}
            />
            {/* Llega en vehículo: Select simple (booleano no admite multi-select) */}
            <div className='flex w-full flex-col gap-1 sm:w-48'>
              <span className='text-xs font-medium text-muted-foreground'>Llega en vehículo</span>
              <Select
                value={filters.arrivesBy}
                onValueChange={(v) => applyFilters({ ...filters, arrivesBy: v as ArrivesBy })}
              >
                <SelectTrigger className='h-9 w-full bg-card font-normal'>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value='all'>Todos</SelectItem>
                  <SelectItem value='vehicle'>Sí, en vehículo</SelectItem>
                  <SelectItem value='on_foot'>No, a pie</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <DateRangeFilter
              label='Vigencia del QR'
              value={filters.qrExpiresAt}
              onChange={(qrExpiresAt) => applyFilters({ ...filters, qrExpiresAt })}
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
                onClick={() => applyFilters(INITIAL_VISITOR_FILTERS)}
              >
                Limpiar filtros
              </Button>
            </div>
          )}
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState title='Sin visitantes registrados' />
        ) : (
          <>
            <ResponsiveDataTable<IVisitor>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Visitante</TableHead>
                    <TableHead scope='col'>Unidad</TableHead>
                    <TableHead scope='col'>Motivo</TableHead>
                    <TableHead scope='col'>Vehículo</TableHead>
                    <TableHead scope='col'>QR expira</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='text-right' />
                  </TableRow>
                </TableHeader>
              }
              renderRow={(visitor) => (
                <TableRow key={visitor.id}>
                  <TableCell className='font-medium'>
                    {visitor.name}
                    {visitor.created_by_user?.name && (
                      <span className='block text-[11.5px] font-normal text-[var(--ink-3)]'>
                        Registró: {visitor.created_by_user.name}
                      </span>
                    )}
                  </TableCell>
                  <TableCell>
                    {visitor.unit?.full_identifier ?? visitor.unit?.number ?? '—'}
                  </TableCell>
                  <TableCell>{visitor.visitor_motive?.name ?? '—'}</TableCell>
                  <TableCell>
                    <VehicleValue visitor={visitor} />
                  </TableCell>
                  <TableCell>
                    {visitor.qr_expires_at ? displayFormatDate(visitor.qr_expires_at) : '—'}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone='b'>{visitor.visitor_status?.name ?? '—'}</StatusPill>
                  </TableCell>
                  <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                    <VisitorActions
                      visitor={visitor}
                      isPending={actionMutation.isPending}
                      onShowQr={setQrVisitor}
                      onCancel={setCancelVisitor}
                      onApprove={handleApprove}
                      onReject={handleReject}
                    />
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(visitor) => visitor.name}
              renderCardFields={(visitor) => [
                {
                  label: 'Unidad',
                  value: visitor.unit?.full_identifier ?? visitor.unit?.number ?? '—',
                },
                { label: 'Motivo', value: visitor.visitor_motive?.name ?? '—' },
                { label: 'Vehículo', value: <VehicleValue visitor={visitor} /> },
                {
                  label: 'QR expira',
                  value: visitor.qr_expires_at ? displayFormatDate(visitor.qr_expires_at) : '—',
                },
                {
                  label: 'Estado',
                  value: <StatusPill tone='b'>{visitor.visitor_status?.name ?? '—'}</StatusPill>,
                },
              ]}
              renderActions={(visitor) => (
                <VisitorActions
                  visitor={visitor}
                  isPending={actionMutation.isPending}
                  onShowQr={setQrVisitor}
                  onCancel={setCancelVisitor}
                  onApprove={handleApprove}
                  onReject={handleReject}
                />
              )}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
