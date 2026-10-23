import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { FollowUpFormModal } from '@/modules/followUp/FollowUpFormModal'
import { followUpChildName, type IFollowUp } from '@/modules/followUp/schema'
import { followUpStatusTone, followUpTypeLabel } from '@/modules/followUp/utils/statusCopy'
import { useFollowUpList } from '@/modules/followUp/hooks/useFollowUpList'
import { useFollowUpStore } from '@/modules/followUp/store/followUpStore'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { MultiSelectFilter } from '@/shared/components/filters/MultiSelectFilter'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import type { Filter } from '@/shared/interfaces/list.types'
import { displayFormatDate, todayLocalDateString } from '@/shared/utils/dateHelper'
import { addCivilDays } from '@/shared/utils/tenantTime'
import { Plus } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

const STATUS_REQUEST = { [CatalogType.FOLLOW_UP_STATUS]: { _sort: 'sort_order' } }

type DueWindow = 'overdue' | 'next_7_days' | 'all'

/**
 * REQ-FUP-009 — work inbox: server-paginated, status filter (lazy catalog
 * load) plus a due-window quick filter, default-sorted by due_date asc.
 */
export function FollowUpList() {
  const navigate = useNavigate()
  const { onSearch, onReset, onPageChanged, paramsSearch } = useFollowUpList()
  const { entities, pagination, isLoading } = useFollowUpStore()

  const [statusFilter, setStatusFilter] = useState<string[]>([])
  const [dueWindow, setDueWindow] = useState<DueWindow>('all')
  const [formOpen, setFormOpen] = useState(false)

  const statusCatalog = useLazyCatalogs(useMemo(() => STATUS_REQUEST, []))

  const applyFilters = useCallback(
    (next: { status?: string[]; dueWindow?: DueWindow }) => {
      const status = next.status ?? statusFilter
      const window = next.dueWindow ?? dueWindow

      const filters: Filter[] = []

      if (status.length > 0) {
        filters.push({ field: 'follow_up_status_id', operator: 'in', value: status })
      }

      if (window === 'overdue') {
        filters.push({ field: 'due_date', operator: '<', value: todayLocalDateString() })
      } else if (window === 'next_7_days') {
        const today = todayLocalDateString()
        filters.push({
          field: 'due_date',
          operator: 'between',
          value: [today, addCivilDays(today, 7)],
        })
      }

      onSearch({ filters })
    },
    [statusFilter, dueWindow, onSearch]
  )

  const handleDetail = useCallback(
    (entity: IFollowUp) => navigate(`/follow-up/${entity.id}`),
    [navigate]
  )

  return (
    <div className='space-y-6'>
      <div className='flex flex-wrap items-center justify-between gap-4'>
        <PageHeader
          title='Seguimientos'
          description='Conductas derivadas del resultado de una Prueba EDI'
        />
        <PermissionGuard permission='create.follow_up'>
          <Button onClick={() => setFormOpen(true)} className='flex items-center gap-2'>
            <Plus className='h-4 w-4' />
            Nuevo seguimiento
          </Button>
        </PermissionGuard>
      </div>

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar…'
        >
          <div className='flex flex-wrap gap-3'>
            <MultiSelectFilter
              label='Estado'
              options={(statusCatalog.catalogs[CatalogType.FOLLOW_UP_STATUS] ?? []).map((item) => ({
                value: item.id,
                label: item.name,
              }))}
              value={statusFilter}
              onChange={(next) => {
                setStatusFilter(next)
                applyFilters({ status: next })
              }}
              isLoading={statusCatalog.isLoading}
              onFirstOpen={statusCatalog.enable}
            />
            <div className='flex gap-1'>
              {(
                [
                  ['all', 'Todos'],
                  ['overdue', 'Vencidos'],
                  ['next_7_days', 'Próximos 7 días'],
                ] as const
              ).map(([value, label]) => (
                <Button
                  key={value}
                  type='button'
                  size='sm'
                  variant={dueWindow === value ? 'default' : 'outline'}
                  onClick={() => {
                    setDueWindow(value)
                    applyFilters({ dueWindow: value })
                  }}
                >
                  {label}
                </Button>
              ))}
            </div>
          </div>
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IFollowUp>
              rows={entities}
              breakpoint='lg'
              onRowClick={handleDetail}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Niño</TableHead>
                    <TableHead scope='col'>Tipo</TableHead>
                    <TableHead scope='col'>Fecha límite</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='text-right' />
                  </TableRow>
                </TableHeader>
              }
              renderRow={(entity) => (
                <TableRow key={entity.id}>
                  <TableCell className='font-medium'>{followUpChildName(entity.child)}</TableCell>
                  <TableCell>{followUpTypeLabel(entity.follow_up_type?.key)}</TableCell>
                  <TableCell>{displayFormatDate(entity.due_date, 'mediumDate')}</TableCell>
                  <TableCell>
                    <StatusPill tone={followUpStatusTone(entity.follow_up_status?.key)}>
                      {entity.follow_up_status?.name ?? '—'}
                    </StatusPill>
                  </TableCell>
                  <TableCell className='text-right'>
                    <Button variant='ghost' size='sm' onClick={() => handleDetail(entity)}>
                      Ver detalle
                    </Button>
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(entity) => followUpChildName(entity.child)}
              renderCardFields={(entity) => [
                { label: 'Tipo', value: followUpTypeLabel(entity.follow_up_type?.key) },
                { label: 'Fecha límite', value: displayFormatDate(entity.due_date, 'mediumDate') },
                { label: 'Estado', value: entity.follow_up_status?.name ?? '—' },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      {formOpen && (
        <FollowUpFormModal
          onClose={() => setFormOpen(false)}
          onCreated={() => {
            setFormOpen(false)
            onSearch()
          }}
        />
      )}
    </div>
  )
}
