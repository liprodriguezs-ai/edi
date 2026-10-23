import { Button } from '@/components/ui/button'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ApplyVisitPickerDialog } from '@/modules/screening/components/ApplyVisitPickerDialog'
import { ScreeningTableRow } from '@/modules/screening/components/ScreeningTableRow'
import { useScreeningList } from '@/modules/screening/hooks/useScreeningList'
import type { IScreening } from '@/modules/screening/schema'
import { useScreeningStore } from '@/modules/screening/store/screeningStore'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { MultiSelectFilter } from '@/shared/components/filters/MultiSelectFilter'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { screeningStatusLabel } from '@/modules/screening/utils/statusCopy'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { Plus } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

const STATUS_REQUEST = { [CatalogType.SCREENING_STATUS]: { _sort: 'name' } }
const COLOR_REQUEST = { [CatalogType.RESULT_COLOR]: { _sort: 'sort_order' } }
const HEALTH_CENTER_REQUEST = { [CatalogType.HEALTH_CENTER]: { _sort: 'name' } }

/**
 * REQ-SUI-002 — server-paginated list; filters load lazily (no `POST
 * /selects` on mount, one `useLazyCatalogs` per filter for max granularity).
 */
export function ScreeningList() {
  const navigate = useNavigate()
  const { onPageChanged, onSearch, onReset, paramsSearch } = useScreeningList()
  const { entities, pagination, isLoading } = useScreeningStore()

  const [pickerOpen, setPickerOpen] = useState(false)
  const [statusFilter, setStatusFilter] = useState<string[]>([])
  const [colorFilter, setColorFilter] = useState<string[]>([])
  const [centerFilter, setCenterFilter] = useState<string[]>([])

  const statusCatalog = useLazyCatalogs(useMemo(() => STATUS_REQUEST, []))
  const colorCatalog = useLazyCatalogs(useMemo(() => COLOR_REQUEST, []))
  const centerCatalog = useLazyCatalogs(useMemo(() => HEALTH_CENTER_REQUEST, []))

  const applyFilters = useCallback(
    (next: { status?: string[]; color?: string[]; center?: string[] }) => {
      const status = next.status ?? statusFilter
      const color = next.color ?? colorFilter
      const center = next.center ?? centerFilter

      const filters: Array<{ field: string; operator: 'in'; value: string[] }> = [
        status.length > 0 ? { field: 'screening_status_id', operator: 'in', value: status } : null,
        color.length > 0 ? { field: 'result_color_id', operator: 'in', value: color } : null,
        center.length > 0 ? { field: 'visit.health_center_id', operator: 'in', value: center } : null,
      ].filter((f): f is { field: string; operator: 'in'; value: string[] } => f !== null)

      onSearch({ filters })
    },
    [statusFilter, colorFilter, centerFilter, onSearch]
  )

  const handleContinue = useCallback(
    (entity: IScreening) => navigate(`/screening/${entity.id}/apply`),
    [navigate]
  )
  const handleDetail = useCallback((entity: IScreening) => navigate(`/screening/${entity.id}`), [navigate])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <PageHeader title="Prueba EDI" description="Aplicaciones del instrumento EDI por visita" />
        <PermissionGuard permission="create.screening">
          <Button onClick={() => setPickerOpen(true)} className="flex items-center gap-2">
            <Plus className="h-4 w-4" />
            Aplicar Prueba EDI
          </Button>
        </PermissionGuard>
      </div>

      <section className="space-y-6">
        <SearchList paramsSearch={paramsSearch} onSearch={onSearch} onReset={onReset} placeholder="Buscar…">
          <div className="flex flex-wrap gap-3">
            <MultiSelectFilter
              label="Estado"
              options={(statusCatalog.catalogs[CatalogType.SCREENING_STATUS] ?? []).map((item) => ({
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
            <MultiSelectFilter
              label="Color global"
              options={(colorCatalog.catalogs[CatalogType.RESULT_COLOR] ?? []).map((item) => ({
                value: item.id,
                label: item.name,
              }))}
              value={colorFilter}
              onChange={(next) => {
                setColorFilter(next)
                applyFilters({ color: next })
              }}
              isLoading={colorCatalog.isLoading}
              onFirstOpen={colorCatalog.enable}
            />
            <MultiSelectFilter
              label="Centro de salud"
              options={(centerCatalog.catalogs[CatalogType.HEALTH_CENTER] ?? []).map((item) => ({
                value: item.id,
                label: item.name,
              }))}
              value={centerFilter}
              onChange={(next) => {
                setCenterFilter(next)
                applyFilters({ center: next })
              }}
              isLoading={centerCatalog.isLoading}
              onFirstOpen={centerCatalog.enable}
            />
          </div>
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<IScreening>
              rows={entities}
              breakpoint="lg"
              onRowClick={handleDetail}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Niño</TableHead>
                    <TableHead scope="col">Visita</TableHead>
                    <TableHead scope="col" className="hidden md:table-cell">
                      Centro de salud
                    </TableHead>
                    <TableHead scope="col">Estado</TableHead>
                    <TableHead scope="col">Color global</TableHead>
                    <TableHead scope="col" className="hidden lg:table-cell">
                      Situación
                    </TableHead>
                    <TableHead scope="col" className="text-right" />
                  </TableRow>
                </TableHeader>
              }
              renderRow={(entity) => (
                <ScreeningTableRow
                  key={entity.id}
                  entity={entity}
                  onContinue={handleContinue}
                  onDetail={handleDetail}
                />
              )}
              renderCardTitle={(entity) => (entity.child ? entity.child.first_name : '—')}
              renderCardFields={(entity) => [
                { label: 'Estado', value: screeningStatusLabel(entity.screening_status?.key) },
                { label: 'Color global', value: entity.result_color?.name ?? 'Sin calificar' },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ApplyVisitPickerDialog isOpen={pickerOpen} onClose={() => setPickerOpen(false)} />
    </div>
  )
}
