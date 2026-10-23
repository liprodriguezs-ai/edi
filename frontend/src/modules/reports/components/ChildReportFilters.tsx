import { CHILD_REPORT_FILTER } from '@/modules/reports/services/ChildReportResource'
import { type DateRange, DateRangeFilter } from '@/shared/components/filters/DateRangeFilter'
import { MultiSelectFilter } from '@/shared/components/filters/MultiSelectFilter'
import { SearchList } from '@/shared/components/SearchList'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import type { Filter, ISearchParams } from '@/shared/interfaces/list.types'
import { useCallback, useMemo, useState } from 'react'

const HEALTH_CENTER_REQUEST = { [CatalogType.HEALTH_CENTER]: { _sort: 'name' } }
const EMPTY_RANGE: DateRange = { from: null, to: null, shortcut: null }

interface ChildReportFiltersProps {
  paramsSearch: ISearchParams
  onSearch: (_search?: Partial<ISearchParams>) => unknown
  onReset: () => unknown
}

/**
 * Shared filters of the per-child reports: text search (name, CURP, ID),
 * health center (catalog loaded lazily on first open) and the date range
 * of the child's last Prueba EDI.
 */
export function ChildReportFilters({ paramsSearch, onSearch, onReset }: ChildReportFiltersProps) {
  const [centers, setCenters] = useState<string[]>([])
  const [range, setRange] = useState<DateRange>(EMPTY_RANGE)

  const centerCatalog = useLazyCatalogs(useMemo(() => HEALTH_CENTER_REQUEST, []))

  const applyFilters = useCallback(
    (next: { centers?: string[]; range?: DateRange }) => {
      const nextCenters = next.centers ?? centers
      const nextRange = next.range ?? range

      const filters: Filter[] = []
      if (nextCenters.length > 0) {
        filters.push({
          field: CHILD_REPORT_FILTER.HEALTH_CENTER,
          operator: 'in',
          value: nextCenters,
        })
      }
      if (nextRange.from) {
        filters.push({
          field: CHILD_REPORT_FILTER.LAST_TEST_FROM,
          operator: '>=',
          value: nextRange.from,
        })
      }
      if (nextRange.to) {
        filters.push({
          field: CHILD_REPORT_FILTER.LAST_TEST_TO,
          operator: '<=',
          value: nextRange.to,
        })
      }

      onSearch({ filters })
    },
    [centers, range, onSearch]
  )

  const handleReset = useCallback(() => {
    setCenters([])
    setRange(EMPTY_RANGE)
    onReset()
  }, [onReset])

  return (
    <SearchList
      paramsSearch={paramsSearch}
      onSearch={onSearch}
      onReset={handleReset}
      placeholder='Buscar por nombre, CURP o ID…'
    >
      <div className='flex flex-wrap gap-3'>
        <MultiSelectFilter
          label='Centro de salud'
          options={(centerCatalog.catalogs[CatalogType.HEALTH_CENTER] ?? []).map((item) => ({
            value: item.id,
            label: item.name,
          }))}
          value={centers}
          onChange={(next) => {
            setCenters(next)
            applyFilters({ centers: next })
          }}
          isLoading={centerCatalog.isLoading}
          onFirstOpen={centerCatalog.enable}
        />
        <DateRangeFilter
          label='Fecha de la última prueba EDI'
          value={range}
          shortcuts={[]}
          onChange={(next) => {
            setRange(next)
            applyFilters({ range: next })
          }}
        />
      </div>
    </SearchList>
  )
}
