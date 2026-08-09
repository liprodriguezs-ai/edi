import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { IComplaint } from '@/modules/complaint/schema'
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'
import { useComplaintStore } from '@/modules/complaint/store/complaintStore'
import { ComplaintForm } from '@/modules/complaint/ComplaintForm'
import { ComplaintPriorityChip } from '@/modules/complaint/components/ComplaintPriorityChip'
import { ComplaintStatusBadge } from '@/modules/complaint/components/ComplaintStatusBadge'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import type { Filter } from '@/shared/interfaces/list.types'

interface ComplaintFilters {
  statusIds: string[]
  categoryIds: string[]
  priorityIds: string[]
  assigneeIds: string[]
}

const INITIAL_FILTERS: ComplaintFilters = {
  statusIds: [],
  categoryIds: [],
  priorityIds: [],
  assigneeIds: [],
}

function buildFilters(filters: ComplaintFilters): Filter[] {
  const result: Filter[] = []

  if (filters.statusIds.length > 0) {
    result.push({ field: 'complaint_status_id', operator: 'in', value: filters.statusIds })
  }
  if (filters.categoryIds.length > 0) {
    result.push({ field: 'complaint_category_id', operator: 'in', value: filters.categoryIds })
  }
  if (filters.priorityIds.length > 0) {
    result.push({ field: 'complaint_priority_id', operator: 'in', value: filters.priorityIds })
  }
  if (filters.assigneeIds.length > 0) {
    result.push({ field: 'assigned_user_id', operator: 'in', value: filters.assigneeIds })
  }

  return result
}

export const ComplaintListPage = () => {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<ComplaintFilters>(INITIAL_FILTERS)
  const [isFormOpen, setIsFormOpen] = useState(false)

  // ── List (Orion search) ──────────────────────────────────────────────────────
  const { onPageChanged, onSearch, paramsSearch } = useBaseList<IComplaint>({
    store: useComplaintStore,
    resource: ComplaintResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        { relation: 'category' },
        { relation: 'priority' },
        { relation: 'status' },
        { relation: 'assignee' },
      ],
      filters: buildFilters(INITIAL_FILTERS),
    },
  })

  const { entities, pagination, isLoading } = useComplaintStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  // ── Registration eligibility (UX fix) ────────────────────────────────────────
  // create.complaint (Spatie) alone does not guarantee the RUNTIME
  // complaint.reporter_role_keys gate will accept the submit — hide (not
  // disable) "Registrar incidencia" for roles that would be rejected, instead of
  // letting them fill the whole form only to hit a 403. One boolean request per
  // list mount, cached for a while since eligibility rarely changes mid-session.
  const { data: registrationEligibility } = useQuery({
    queryKey: ['complaint', 'registration-eligibility'],
    queryFn: () => ComplaintResource.registrationEligibility(),
    staleTime: 5 * 60 * 1000,
  })
  const canRegister = registrationEligibility?.can_register === true

  // ── Lazy filter catalogs (infra-cost rule — no /selects on mount) ────────────
  const {
    catalogs: statusCatalogs,
    enable: enableStatuses,
    isLoading: loadingStatuses,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.COMPLAINT_STATUS]: { _sort: 'name' } }), []))
  const {
    catalogs: categoryCatalogs,
    enable: enableCategories,
    isLoading: loadingCategories,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.COMPLAINT_CATEGORY]: { _sort: 'order' } }), []))
  const {
    catalogs: priorityCatalogs,
    enable: enablePriorities,
    isLoading: loadingPriorities,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.COMPLAINT_PRIORITY]: { _sort: 'order' } }), []))
  const {
    catalogs: userCatalogs,
    enable: enableUsers,
    isLoading: loadingUsers,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.USER]: { _sort: 'name' } }), []))

  const statusOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusCatalogs[CatalogType.COMPLAINT_STATUS] ?? []).map((s) => ({
        value: s.id,
        label: s.name,
      })),
    [statusCatalogs]
  )
  const categoryOptions = useMemo<MultiSelectOption[]>(
    () =>
      (categoryCatalogs[CatalogType.COMPLAINT_CATEGORY] ?? []).map((c) => ({
        value: c.id,
        label: c.name,
      })),
    [categoryCatalogs]
  )
  const priorityOptions = useMemo<MultiSelectOption[]>(
    () =>
      (priorityCatalogs[CatalogType.COMPLAINT_PRIORITY] ?? []).map((p) => ({
        value: p.id,
        label: p.name,
      })),
    [priorityCatalogs]
  )
  const assigneeOptions = useMemo<MultiSelectOption[]>(
    () =>
      (userCatalogs[CatalogType.USER] ?? []).map((u) => ({
        value: u.id,
        label: u.name,
      })),
    [userCatalogs]
  )

  // ── Filter handlers ──────────────────────────────────────────────────────────
  const applyFilters = useCallback(
    (next: ComplaintFilters) => {
      setFilters(next)
      void onSearch({ ...paramsSearch, page: 1, filters: buildFilters(next) })
    },
    [onSearch, paramsSearch]
  )

  const resetFilters = useCallback(() => {
    setFilters(INITIAL_FILTERS)
    void onSearch({
      sort: paramsSearch.sort,
      includes: paramsSearch.includes,
      page: 1,
      filters: buildFilters(INITIAL_FILTERS),
    })
  }, [onSearch, paramsSearch])

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Incidencias'
        description='Registro y seguimiento de reportes de convivencia, fallas del condominio y quejas contra la administración'
        actions={
          canRegister && (
            <PermissionGuard permission='create.complaint'>
              <Button onClick={() => setIsFormOpen(true)} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                Registrar incidencia
              </Button>
            </PermissionGuard>
          )
        }
      />

      <ComplaintForm
        isOpen={isFormOpen}
        onClose={() => setIsFormOpen(false)}
        onCreated={() => {
          setIsFormOpen(false)
          void onSearch({ ...paramsSearch })
        }}
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={(params) => {
            void onSearch({ ...params, filters: buildFilters(filters), page: 1 })
          }}
          onReset={() => resetFilters()}
          placeholder='Buscar por folio, título o descripción…'
          defaultFiltersExpanded={false}
          toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
        >
          <div className='flex flex-wrap gap-2'>
            <MultiSelectFilter
              label='Estado'
              options={statusOptions}
              value={filters.statusIds}
              onChange={(statusIds) => applyFilters({ ...filters, statusIds })}
              placeholder='Todos los estados'
              compact
              onFirstOpen={enableStatuses}
              isLoading={loadingStatuses}
            />
            <MultiSelectFilter
              label='Categoría'
              options={categoryOptions}
              value={filters.categoryIds}
              onChange={(categoryIds) => applyFilters({ ...filters, categoryIds })}
              placeholder='Todas las categorías'
              compact
              onFirstOpen={enableCategories}
              isLoading={loadingCategories}
            />
            <MultiSelectFilter
              label='Prioridad'
              options={priorityOptions}
              value={filters.priorityIds}
              onChange={(priorityIds) => applyFilters({ ...filters, priorityIds })}
              placeholder='Todas las prioridades'
              compact
              onFirstOpen={enablePriorities}
              isLoading={loadingPriorities}
            />
            <MultiSelectFilter
              label='Asignado a'
              options={assigneeOptions}
              value={filters.assigneeIds}
              onChange={(assigneeIds) => applyFilters({ ...filters, assigneeIds })}
              placeholder='Todos'
              compact
              onFirstOpen={enableUsers}
              isLoading={loadingUsers}
            />
          </div>
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Sin incidencias'
            description='No hay incidencias registradas con los filtros seleccionados.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IComplaint>
              breakpoint='lg'
              rows={entities}
              onRowClick={(complaint) => navigate(`/complaint/${complaint.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Folio</TableHead>
                    <TableHead scope='col'>Título</TableHead>
                    <TableHead scope='col'>Categoría</TableHead>
                    <TableHead scope='col'>Prioridad</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col'>Asignado a</TableHead>
                    <TableHead scope='col'>Registrada</TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(complaint) => (
                <TableRow
                  key={complaint.id}
                  className='cursor-pointer'
                  onClick={() => navigate(`/complaint/${complaint.id}`)}
                >
                  <TableCell className='font-medium tabular-nums'>
                    {complaint.case_number}
                  </TableCell>
                  <TableCell>{complaint.title}</TableCell>
                  <TableCell>{complaint.category?.name ?? '—'}</TableCell>
                  <TableCell>
                    <ComplaintPriorityChip
                      name={complaint.priority?.name}
                      color={complaint.priority?.color}
                    />
                  </TableCell>
                  <TableCell>
                    <ComplaintStatusBadge
                      statusKey={complaint.status?.key ?? null}
                      statusName={complaint.status?.name ?? null}
                      statusColor={complaint.status?.color ?? null}
                    />
                  </TableCell>
                  <TableCell>{complaint.assignee?.name ?? '—'}</TableCell>
                  <TableCell>{displayFormatDate(complaint.created_at, 'mediumDate')}</TableCell>
                </TableRow>
              )}
              renderCardTitle={(complaint) => `${complaint.case_number} — ${complaint.title}`}
              renderCardFields={(complaint) => [
                { label: 'Categoría', value: complaint.category?.name ?? '—' },
                {
                  label: 'Prioridad',
                  value: (
                    <ComplaintPriorityChip
                      name={complaint.priority?.name}
                      color={complaint.priority?.color}
                    />
                  ),
                },
                {
                  label: 'Estado',
                  value: (
                    <ComplaintStatusBadge
                      statusKey={complaint.status?.key ?? null}
                      statusName={complaint.status?.name ?? null}
                      statusColor={complaint.status?.color ?? null}
                    />
                  ),
                },
                { label: 'Asignado a', value: complaint.assignee?.name ?? '—' },
                {
                  label: 'Registrada',
                  value: displayFormatDate(complaint.created_at, 'mediumDate'),
                },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
