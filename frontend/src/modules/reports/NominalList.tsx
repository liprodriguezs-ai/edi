import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useNominalReportList } from '@/modules/reports/hooks/useNominalReportList'
import { useNominalReportStore } from '@/modules/reports/store/nominalReportStore'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import type { IScreening } from '@/modules/screening/schema'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { MultiSelectFilter } from '@/shared/components/filters/MultiSelectFilter'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Download } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'

const COLOR_REQUEST = { [CatalogType.RESULT_COLOR]: { _sort: 'sort_order' } }
const HEALTH_CENTER_REQUEST = { [CatalogType.HEALTH_CENTER]: { _sort: 'name' } }

/**
 * REQ-RPT-006/011 — nominal listing (PII surface, own permission). Same
 * lazy-filter pattern as ScreeningList; export honors the active filters
 * via `useBaseList().onReport()` (the house `/customReport` mechanism).
 */
export function NominalList() {
  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useNominalReportList()
  const { entities, pagination, isLoading } = useNominalReportStore()

  const [colorFilter, setColorFilter] = useState<string[]>([])
  const [centerFilter, setCenterFilter] = useState<string[]>([])

  const colorCatalog = useLazyCatalogs(useMemo(() => COLOR_REQUEST, []))
  const centerCatalog = useLazyCatalogs(useMemo(() => HEALTH_CENTER_REQUEST, []))

  const applyFilters = useCallback(
    (next: { color?: string[]; center?: string[] }) => {
      const color = next.color ?? colorFilter
      const center = next.center ?? centerFilter

      const filters: Array<{ field: string; operator: 'in'; value: string[] }> = [
        color.length > 0 ? { field: 'result_color_id', operator: 'in', value: color } : null,
        center.length > 0
          ? { field: 'visit.health_center_id', operator: 'in', value: center }
          : null,
      ].filter((f): f is { field: string; operator: 'in'; value: string[] } => f !== null)

      onSearch({ filters })
    },
    [colorFilter, centerFilter, onSearch]
  )

  return (
    <div className='space-y-6'>
      <div className='flex flex-wrap items-center justify-between gap-4'>
        <PageHeader
          title='Listado nominal'
          description='Pruebas EDI aplicadas, con datos de identificación del niño'
        />
        <PermissionGuard permission='report.screening'>
          <Button
            variant='outline'
            onClick={() => onReport('Screening', 'xlsx', 'Listado nominal')}
            loading={isExporting}
            className='flex items-center gap-2'
          >
            {!isExporting && <Download className='h-4 w-4' />}
            Exportar a Excel
          </Button>
        </PermissionGuard>
      </div>

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar por nombre o CURP…'
        >
          <div className='flex flex-wrap gap-3'>
            <MultiSelectFilter
              label='Color global'
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
              label='Centro de salud'
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
              breakpoint='lg'
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Niño</TableHead>
                    <TableHead scope='col'>CURP</TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Centro de salud
                    </TableHead>
                    <TableHead scope='col'>Fecha de visita</TableHead>
                    <TableHead scope='col'>Color global</TableHead>
                    <TableHead scope='col' className='hidden lg:table-cell'>
                      Situación
                    </TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(entity) => (
                <TableRow key={entity.id}>
                  <TableCell className='font-medium'>
                    {entity.child
                      ? `${entity.child.first_name} ${entity.child.last_name_paternal} ${entity.child.last_name_maternal ?? ''}`.trim()
                      : '—'}
                  </TableCell>
                  <TableCell className='font-mono'>{entity.child?.curp ?? '—'}</TableCell>
                  <TableCell className='hidden md:table-cell'>
                    {entity.visit?.health_center?.name ?? '—'}
                  </TableCell>
                  <TableCell className='font-mono tabular-nums'>
                    {displayFormatDate(entity.visit?.visit_date, 'mediumDate')}
                  </TableCell>
                  <TableCell>
                    <ResultColorPill colorKey={entity.result_color?.key} />
                  </TableCell>
                  <TableCell className='hidden lg:table-cell'>
                    {entity.situation?.name ?? '—'}
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(entity) => (entity.child ? entity.child.first_name : '—')}
              renderCardFields={(entity) => [
                {
                  label: 'CURP',
                  value: <span className='font-mono'>{entity.child?.curp ?? '—'}</span>,
                },
                { label: 'Centro de salud', value: entity.visit?.health_center?.name ?? '—' },
                {
                  label: 'Color global',
                  value: <ResultColorPill colorKey={entity.result_color?.key} />,
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
