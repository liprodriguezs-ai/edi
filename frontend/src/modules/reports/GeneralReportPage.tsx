import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { ChildReportFilters } from '@/modules/reports/components/ChildReportFilters'
import { ExportExcelButton } from '@/modules/reports/components/ExportExcelButton'
import {
  NutritionCells,
  ScreeningCells,
  STICKY_CELL,
} from '@/modules/reports/components/ReportCells'
import { useChildReportExport } from '@/modules/reports/hooks/useChildReportExport'
import { useGeneralReportList } from '@/modules/reports/hooks/useChildReportLists'
import { GeneralReportResource } from '@/modules/reports/services/ChildReportResource'
import { useGeneralReportStore } from '@/modules/reports/store/childReportStores'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { Pagination } from '@/shared/components/Pagination'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Panel } from '@/shared/components/molecules/Panel'

const GROUP_HEAD = 'text-center border-x'

/**
 * S4(b) — "Reporte general" (legacy /censos/ninosano, censo de menores de
 * 5 años): latest measurement with P/T, P/E, T/E, caregiver and the
 * previous and last Prueba EDI of every child.
 */
export function GeneralReportPage() {
  const { onPageChanged, onSearch, onReset, paramsSearch } = useGeneralReportList()
  const entities = useGeneralReportStore((s) => s.entities)
  const pagination = useGeneralReportStore((s) => s.pagination)
  const isLoading = useGeneralReportStore((s) => s.isLoading)
  const { onExport, isExporting } = useChildReportExport(
    GeneralReportResource,
    paramsSearch,
    'Reporte general'
  )

  return (
    <div className='space-y-6'>
      <div className='flex flex-wrap items-center justify-between gap-4'>
        <PageHeader
          title='Reporte general'
          description='Censo de niños menores de 5 años: medición, estado nutricional y Prueba EDI'
        />
        <ExportExcelButton
          permission='report.general_report'
          isExporting={isExporting}
          onExport={onExport}
        />
      </div>

      <section className='space-y-6'>
        <ChildReportFilters paramsSearch={paramsSearch} onSearch={onSearch} onReset={onReset} />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            icon='search'
            title='Sin niños para mostrar'
            description='No hay niños menores de 5 años que coincidan con los filtros.'
          />
        ) : (
          <>
            <Panel as='div' padding='none'>
              <Table className='min-w-max'>
                <TableHeader>
                  <TableRow>
                    <TableHead rowSpan={2} scope='col' className={cn(STICKY_CELL, 'min-w-56')}>
                      Niño
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Centro de salud
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Fecha de nacimiento
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Edad
                    </TableHead>
                    <TableHead colSpan={6} scope='colgroup' className={GROUP_HEAD}>
                      Última medición
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Cuidador
                    </TableHead>
                    <TableHead colSpan={2} scope='colgroup' className={GROUP_HEAD}>
                      Prueba EDI anterior
                    </TableHead>
                    <TableHead colSpan={2} scope='colgroup' className={GROUP_HEAD}>
                      Última prueba EDI
                    </TableHead>
                  </TableRow>
                  <TableRow>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col'>Peso</TableHead>
                    <TableHead scope='col'>Talla</TableHead>
                    <TableHead scope='col'>P/T</TableHead>
                    <TableHead scope='col'>P/E</TableHead>
                    <TableHead scope='col'>T/E</TableHead>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col'>Resultado</TableHead>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col'>Resultado</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {entities.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className={cn(STICKY_CELL, 'font-medium')}>
                        <div>{row.child_name}</div>
                        <div className='font-mono text-xs text-muted-foreground'>
                          {row.curp ?? row.external_id ?? ''}
                        </div>
                      </TableCell>
                      <TableCell>{row.health_center_name ?? '—'}</TableCell>
                      <TableCell className='font-mono tabular-nums whitespace-nowrap'>
                        {displayFormatDate(row.birth_date, 'mediumDate')}
                      </TableCell>
                      <TableCell className='whitespace-nowrap'>{row.age_label}</TableCell>
                      <NutritionCells nutrition={row.nutrition} />
                      <TableCell className='whitespace-nowrap'>
                        {row.caregiver_name ?? '—'}
                      </TableCell>
                      <ScreeningCells screening={row.previous_screening} />
                      <ScreeningCells screening={row.last_screening} />
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Panel>
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
