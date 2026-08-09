import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import type { IPackage } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { usePackageStore } from '@/modules/package/store/packageStore'
import { PackageStatusBadge } from '@/modules/package/components/PackageStatusBadge'
import { PackageAgingTab } from '@/modules/package/components/PackageAgingTab'
import { PackageForm } from '@/modules/package/PackageForm'
import { PackagePickupModal } from '@/modules/package/PackagePickupModal'
import { PackageRowActions } from '@/modules/package/components/PackageRowActions'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Plus, ScanLine } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import type { Filter } from '@/shared/interfaces/list.types'

// ─── Package status UUIDs (central FSM — must match backend PackageStatus constants) ───

const PACKAGE_STATUS_READY = 'a2001001-0000-4000-8000-000000000002'
const PACKAGE_STATUS_PICKED_UP = 'a2001001-0000-4000-8000-000000000003'
const PACKAGE_STATUS_RETURNED = 'a2001001-0000-4000-8000-000000000004'
const PACKAGE_STATUS_INCIDENT = 'a2001001-0000-4000-8000-000000000005'

type PackageTab = 'pending' | 'history' | 'aging'

// ─── Package primary action (Entregar) ───────────────────────────────────────

function PackageDeliverButton({
  pkg,
  onPickup,
}: {
  pkg: IPackage
  onPickup: (_pkg: IPackage) => void
}) {
  if (pkg.package_status_id !== PACKAGE_STATUS_READY) return null

  return (
    <Button
      size='sm'
      variant='outline'
      className='h-7 rounded-full px-2.5 text-[12px]'
      onClick={() => onPickup(pkg)}
      aria-label={`Entregar paquete de ${pkg.unit?.full_identifier ?? pkg.unit?.number ?? ''}`}
    >
      <ScanLine className='size-3.5' />
      Entregar
    </Button>
  )
}

// ─── Pending tab filters ──────────────────────────────────────────────────────

interface PendingFilters {
  unitIds: string[]
  carrierIds: string[]
  statusIds: string[]
}

const INITIAL_PENDING_FILTERS: PendingFilters = {
  unitIds: [],
  carrierIds: [],
  statusIds: [PACKAGE_STATUS_READY],
}

function buildPendingFilters(filters: PendingFilters): Filter[] {
  const result: Filter[] = []

  if (filters.statusIds.length > 0) {
    result.push({ field: 'package_status_id', operator: 'in', value: filters.statusIds })
  }
  if (filters.unitIds.length > 0) {
    result.push({ field: 'unit_id', operator: 'in', value: filters.unitIds })
  }
  if (filters.carrierIds.length > 0) {
    result.push({ field: 'package_carrier_id', operator: 'in', value: filters.carrierIds })
  }

  return result
}

// ─── Age-highlight helper ─────────────────────────────────────────────────────

/** Returns a CSS class based on how long ago the package was received. */
function ageHighlightClass(receivedAt: string | null | undefined): string {
  if (!receivedAt) return ''
  const days = Math.floor((Date.now() - new Date(receivedAt).getTime()) / 86_400_000)
  if (days > 7) return 'text-red-600 font-semibold'
  if (days > 3) return 'text-amber-600'
  return ''
}

// ─── Main page ────────────────────────────────────────────────────────────────

export const PackageListPage = () => {
  // create.package  → guards, admins, security staff (can register + see management list)
  // read.report_package → board read-only roles (Treasurer, Oversight Committee) that can
  //   see the management list + aging report but cannot register packages.
  // Residents/renters have neither → they are sent to /my-packages.
  // All hooks must run unconditionally BEFORE the early return (React rules).
  const { canCreate, canReport } = useMultiplePermissions({
    canCreate: 'create.package',
    canReport: 'read.report_package',
  })

  const [activeTab, setActiveTab] = useState<PackageTab>('pending')
  const [pendingFilters, setPendingFilters] = useState<PendingFilters>(INITIAL_PENDING_FILTERS)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [pickupTarget, setPickupTarget] = useState<IPackage | null>(null)
  const [isPickupOpen, setIsPickupOpen] = useState(false)

  // ── List (Orion search) ──────────────────────────────────────────────────────
  const { onPageChanged, onSearch, paramsSearch } = useBaseList<IPackage>({
    store: usePackageStore,
    resource: PackageResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'received_at', direction: 'asc' }],
      includes: [
        { relation: 'unit' },
        { relation: 'status' },
        { relation: 'carrier' },
        { relation: 'type' },
      ],
      filters: buildPendingFilters(INITIAL_PENDING_FILTERS),
    },
  })

  const { entities, pagination, isLoading } = usePackageStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  // ── Aging report (TanStack Query) ────────────────────────────────────────────
  const agingQuery = useQuery({
    queryKey: ['package-aging'],
    queryFn: () => PackageResource.aging(),
    enabled: activeTab === 'aging',
  })

  // ── Lazy filter catalogs ─────────────────────────────────────────────────────
  const {
    catalogs: unitCatalogs,
    enable: enableUnits,
    isLoading: loadingUnits,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.UNIT]: { _sort: 'number' } }), []))
  const {
    catalogs: carrierCatalogs,
    enable: enableCarriers,
    isLoading: loadingCarriers,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.PACKAGE_CARRIER]: { _sort: 'order' } }), []))

  const unitOptions = useMemo<MultiSelectOption[]>(
    () =>
      (unitCatalogs[CatalogType.UNIT] ?? []).map((u) => ({
        value: u.id,
        label:
          (u.full_identifier as string | undefined) ?? (u.number as string | undefined) ?? u.id,
      })),
    [unitCatalogs]
  )
  const carrierOptions = useMemo<MultiSelectOption[]>(
    () =>
      (carrierCatalogs[CatalogType.PACKAGE_CARRIER] ?? []).map((c) => ({
        value: c.id,
        label: (c.name as string | undefined) ?? c.id,
      })),
    [carrierCatalogs]
  )

  // ── Export mutation ──────────────────────────────────────────────────────────
  const exportMutation = useMutation({
    mutationFn: () => PackageResource.agingExport(),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = 'paquetes-antiguedad.xlsx'
      a.click()
      URL.revokeObjectURL(url)
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error).title : 'No se pudo exportar'
      ),
  })

  // ── Filter handlers ──────────────────────────────────────────────────────────
  const applyFilters = useCallback(
    (next: PendingFilters) => {
      setPendingFilters(next)
      void onSearch({ ...paramsSearch, page: 1, filters: buildPendingFilters(next) })
    },
    [onSearch, paramsSearch]
  )

  const resetFilters = useCallback(() => {
    setPendingFilters(INITIAL_PENDING_FILTERS)
    void onSearch({
      sort: paramsSearch.sort,
      includes: paramsSearch.includes,
      page: 1,
      filters: buildPendingFilters(INITIAL_PENDING_FILTERS),
    })
  }, [onSearch, paramsSearch])

  const handlePickupSuccess = useCallback(() => {
    setIsPickupOpen(false)
    setPickupTarget(null)
    void onSearch({ ...paramsSearch })
  }, [onSearch, paramsSearch])

  // Refreshes the list after a return / incident / reassign action in a row.
  const handleRowActionSuccess = useCallback(() => {
    void onSearch({ ...paramsSearch })
  }, [onSearch, paramsSearch])

  // ── Tab change handler ───────────────────────────────────────────────────────
  const handleTabChange = useCallback(
    (tab: string) => {
      const next = tab as PackageTab
      setActiveTab(next)
      if (next === 'history') {
        void onSearch({
          sort: [{ field: 'received_at', direction: 'desc' }],
          includes: paramsSearch.includes,
          page: 1,
          filters: [],
        })
      } else if (next === 'pending') {
        void onSearch({
          sort: paramsSearch.sort,
          includes: paramsSearch.includes,
          page: 1,
          filters: buildPendingFilters(pendingFilters),
        })
      }
      // 'aging' tab: does not touch the list query
    },
    [onSearch, paramsSearch, pendingFilters]
  )

  // Residents/renters have neither create.package nor read.report_package →
  // redirect to their personal QR page. Board read-only roles (canReport) and
  // staff (canCreate) stay on the management list.
  if (!canCreate && !canReport) {
    return <Navigate to='/my-packages' replace />
  }

  return (
    <div className='space-y-6'>
      {/* Header */}
      <PageHeader
        title='Paquetería'
        description='Recepción, entrega y seguimiento de paquetes en la portería'
        actions={
          <>
            <PermissionGuard permission='update.package'>
              <Button
                variant='outline'
                onClick={() => {
                  setPickupTarget(null)
                  setIsPickupOpen(true)
                }}
                className='flex items-center gap-2'
              >
                <ScanLine className='h-4 w-4' />
                Entregar / Escanear código
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.package'>
              <Button onClick={() => setIsFormOpen(true)} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                Registrar paquete
              </Button>
            </PermissionGuard>
          </>
        }
      />

      {/* Form modal */}
      <PackageForm
        isOpen={isFormOpen}
        onClose={() => setIsFormOpen(false)}
        onCreated={() => {
          setIsFormOpen(false)
          void onSearch({ ...paramsSearch })
        }}
      />

      {/* Pickup modal */}
      <PackagePickupModal
        isOpen={isPickupOpen}
        initialPackage={pickupTarget}
        onClose={() => {
          setIsPickupOpen(false)
          setPickupTarget(null)
        }}
        onHandedOver={handlePickupSuccess}
      />

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={handleTabChange}>
        <TabsList>
          <TabsTrigger value='pending'>Pendientes de retiro</TabsTrigger>
          <TabsTrigger value='history'>Historial / Todos</TabsTrigger>
          <TabsTrigger value='aging'>Reporte de antigüedad</TabsTrigger>
        </TabsList>
      </Tabs>

      {/* ── Pending tab ── */}
      {activeTab === 'pending' && (
        <section className='space-y-6'>
          <SearchList
            paramsSearch={paramsSearch}
            onSearch={(params) => {
              void onSearch({ ...params, filters: buildPendingFilters(pendingFilters), page: 1 })
            }}
            onReset={() => resetFilters()}
            placeholder='Buscar por destinatario o tracking…'
            defaultFiltersExpanded={false}
            toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
          >
            <div className='flex flex-wrap gap-2'>
              <MultiSelectFilter
                label='Unidad'
                options={unitOptions}
                value={pendingFilters.unitIds}
                onChange={(unitIds) => applyFilters({ ...pendingFilters, unitIds })}
                placeholder='Todas las unidades'
                compact
                onFirstOpen={enableUnits}
                isLoading={loadingUnits}
              />
              <MultiSelectFilter
                label='Transportista'
                options={carrierOptions}
                value={pendingFilters.carrierIds}
                onChange={(carrierIds) => applyFilters({ ...pendingFilters, carrierIds })}
                placeholder='Todos los transportistas'
                compact
                onFirstOpen={enableCarriers}
                isLoading={loadingCarriers}
              />
              <MultiSelectFilter
                label='Estado'
                options={[
                  { value: PACKAGE_STATUS_READY, label: 'Listo' },
                  { value: PACKAGE_STATUS_PICKED_UP, label: 'Retirado' },
                  { value: PACKAGE_STATUS_RETURNED, label: 'Devuelto' },
                  { value: PACKAGE_STATUS_INCIDENT, label: 'Incidente' },
                ]}
                value={pendingFilters.statusIds}
                onChange={(statusIds) => applyFilters({ ...pendingFilters, statusIds })}
                placeholder='Todos los estados'
                compact
              />
            </div>
          </SearchList>

          {isLoading ? (
            <TableLoadingState />
          ) : entities.length === 0 ? (
            <TableEmptyState
              title='Sin paquetes'
              description='No hay paquetes pendientes con los filtros seleccionados.'
            />
          ) : (
            <>
              <ResponsiveDataTable<IPackage>
                breakpoint='lg'
                rows={entities}
                header={
                  <TableHeader>
                    <TableRow>
                      <TableHead scope='col'>Unidad</TableHead>
                      <TableHead scope='col'>Destinatario</TableHead>
                      <TableHead scope='col'>Transportista</TableHead>
                      <TableHead scope='col'>Tipo</TableHead>
                      <TableHead scope='col'>Recibido</TableHead>
                      <TableHead scope='col'>Estado</TableHead>
                      <TableHead scope='col' className='text-right' />
                    </TableRow>
                  </TableHeader>
                }
                renderRow={(pkg) => (
                  <TableRow key={pkg.id}>
                    <TableCell className='font-medium'>
                      {pkg.unit?.full_identifier ?? pkg.unit?.number ?? '—'}
                    </TableCell>
                    <TableCell>{pkg.recipient_name ?? pkg.recipient_user?.name ?? '—'}</TableCell>
                    <TableCell>{pkg.carrier?.name ?? pkg.carrier_other ?? '—'}</TableCell>
                    <TableCell>{pkg.type?.name ?? '—'}</TableCell>
                    <TableCell>
                      <span className={ageHighlightClass(pkg.received_at)}>
                        {pkg.received_at ? displayFormatDate(pkg.received_at, 'mediumDate') : '—'}
                      </span>
                    </TableCell>
                    <TableCell>
                      <PackageStatusBadge
                        statusKey={pkg.status?.key ?? null}
                        statusName={pkg.status?.name ?? null}
                        statusColor={pkg.status?.color ?? null}
                      />
                    </TableCell>
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <PermissionGuard permission='update.package'>
                        <div className='flex items-center justify-end gap-1'>
                          <PackageDeliverButton
                            pkg={pkg}
                            onPickup={(p) => {
                              setPickupTarget(p)
                              setIsPickupOpen(true)
                            }}
                          />
                          <PackageRowActions pkg={pkg} onSuccess={handleRowActionSuccess} />
                        </div>
                      </PermissionGuard>
                    </TableCell>
                  </TableRow>
                )}
                renderCardTitle={(pkg) => pkg.unit?.full_identifier ?? pkg.unit?.number ?? '—'}
                renderCardFields={(pkg) => [
                  {
                    label: 'Destinatario',
                    value: pkg.recipient_name ?? pkg.recipient_user?.name ?? '—',
                  },
                  {
                    label: 'Transportista',
                    value: pkg.carrier?.name ?? pkg.carrier_other ?? '—',
                  },
                  { label: 'Tipo', value: pkg.type?.name ?? '—' },
                  {
                    label: 'Recibido',
                    value: (
                      <span className={ageHighlightClass(pkg.received_at)}>
                        {pkg.received_at ? displayFormatDate(pkg.received_at, 'mediumDate') : '—'}
                      </span>
                    ),
                  },
                  {
                    label: 'Estado',
                    value: (
                      <PackageStatusBadge
                        statusKey={pkg.status?.key ?? null}
                        statusName={pkg.status?.name ?? null}
                        statusColor={pkg.status?.color ?? null}
                      />
                    ),
                  },
                ]}
                renderActions={(pkg) => (
                  <PermissionGuard permission='update.package'>
                    <div className='flex items-center gap-1'>
                      <PackageDeliverButton
                        pkg={pkg}
                        onPickup={(p) => {
                          setPickupTarget(p)
                          setIsPickupOpen(true)
                        }}
                      />
                      <PackageRowActions pkg={pkg} onSuccess={handleRowActionSuccess} />
                    </div>
                  </PermissionGuard>
                )}
              />
              <Pagination pagination={pagination} onPageChanged={onPageChanged} />
            </>
          )}
        </section>
      )}

      {/* ── History tab: all statuses ── */}
      {activeTab === 'history' && (
        <section className='space-y-6'>
          {isLoading ? (
            <TableLoadingState />
          ) : entities.length === 0 ? (
            <TableEmptyState
              title='Sin paquetes registrados'
              description='No hay paquetes en el sistema con los filtros actuales.'
            />
          ) : (
            <>
              <ResponsiveDataTable<IPackage>
                breakpoint='lg'
                rows={entities}
                header={
                  <TableHeader>
                    <TableRow>
                      <TableHead scope='col'>Unidad</TableHead>
                      <TableHead scope='col'>Destinatario</TableHead>
                      <TableHead scope='col'>Transportista</TableHead>
                      <TableHead scope='col'>Tipo</TableHead>
                      <TableHead scope='col'>Recibido</TableHead>
                      <TableHead scope='col'>Estado</TableHead>
                      <TableHead scope='col' className='text-right' />
                    </TableRow>
                  </TableHeader>
                }
                renderRow={(pkg) => (
                  <TableRow key={pkg.id}>
                    <TableCell className='font-medium'>
                      {pkg.unit?.full_identifier ?? pkg.unit?.number ?? '—'}
                    </TableCell>
                    <TableCell>{pkg.recipient_name ?? pkg.recipient_user?.name ?? '—'}</TableCell>
                    <TableCell>{pkg.carrier?.name ?? pkg.carrier_other ?? '—'}</TableCell>
                    <TableCell>{pkg.type?.name ?? '—'}</TableCell>
                    <TableCell>
                      <span className={ageHighlightClass(pkg.received_at)}>
                        {pkg.received_at ? displayFormatDate(pkg.received_at, 'mediumDate') : '—'}
                      </span>
                    </TableCell>
                    <TableCell>
                      <PackageStatusBadge
                        statusKey={pkg.status?.key ?? null}
                        statusName={pkg.status?.name ?? null}
                        statusColor={pkg.status?.color ?? null}
                      />
                    </TableCell>
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <PermissionGuard permission='update.package'>
                        <div className='flex items-center justify-end gap-1'>
                          <PackageDeliverButton
                            pkg={pkg}
                            onPickup={(p) => {
                              setPickupTarget(p)
                              setIsPickupOpen(true)
                            }}
                          />
                          <PackageRowActions pkg={pkg} onSuccess={handleRowActionSuccess} />
                        </div>
                      </PermissionGuard>
                    </TableCell>
                  </TableRow>
                )}
                renderCardTitle={(pkg) => pkg.unit?.full_identifier ?? pkg.unit?.number ?? '—'}
                renderCardFields={(pkg) => [
                  {
                    label: 'Destinatario',
                    value: pkg.recipient_name ?? pkg.recipient_user?.name ?? '—',
                  },
                  {
                    label: 'Transportista',
                    value: pkg.carrier?.name ?? pkg.carrier_other ?? '—',
                  },
                  { label: 'Tipo', value: pkg.type?.name ?? '—' },
                  {
                    label: 'Recibido',
                    value: (
                      <span className={ageHighlightClass(pkg.received_at)}>
                        {pkg.received_at ? displayFormatDate(pkg.received_at, 'mediumDate') : '—'}
                      </span>
                    ),
                  },
                  {
                    label: 'Estado',
                    value: (
                      <PackageStatusBadge
                        statusKey={pkg.status?.key ?? null}
                        statusName={pkg.status?.name ?? null}
                        statusColor={pkg.status?.color ?? null}
                      />
                    ),
                  },
                ]}
                renderActions={(pkg) => (
                  <PermissionGuard permission='update.package'>
                    <div className='flex items-center gap-1'>
                      <PackageDeliverButton
                        pkg={pkg}
                        onPickup={(p) => {
                          setPickupTarget(p)
                          setIsPickupOpen(true)
                        }}
                      />
                      <PackageRowActions pkg={pkg} onSuccess={handleRowActionSuccess} />
                    </div>
                  </PermissionGuard>
                )}
              />
              <Pagination pagination={pagination} onPageChanged={onPageChanged} />
            </>
          )}
        </section>
      )}

      {/* ── Aging tab ── */}
      {activeTab === 'aging' && (
        <PackageAgingTab
          aging={agingQuery.data}
          isLoading={agingQuery.isLoading}
          isError={agingQuery.isError}
          exportPending={exportMutation.isPending}
          onExport={() => exportMutation.mutate()}
        />
      )}
    </div>
  )
}
