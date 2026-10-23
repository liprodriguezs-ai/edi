import { useMemo } from 'react'
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
import { useEdiConcentrateList } from '@/modules/reports/hooks/useChildReportLists'
import { impactAxesFromMeta } from '@/modules/reports/schema'
import { EdiConcentrateResource } from '@/modules/reports/services/ChildReportResource'
import { formatMonthYear } from '@/modules/reports/utils/reportFormat'
import { useEdiConcentrateStore } from '@/modules/reports/store/childReportStores'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { Pagination } from '@/shared/components/Pagination'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Panel } from '@/shared/components/molecules/Panel'

const GROUP_HEAD = 'text-center border-x'

/**
 * S4(a) — "Concentrado EDI" (legacy /censos/edi-resumen): one row per child
 * with the previous and the last Prueba EDI, the axes with impact, the
 * latest nutritional status, caregiver and next evaluation.
 */
export function EdiConcentratePage() {
  const { onPageChanged, onSearch, onReset, paramsSearch } = useEdiConcentrateList()
  const meta = useEdiConcentrateStore((state) => state.meta)
  const impactAxes = useMemo(() => impactAxesFromMeta(meta), [meta])
  const entities = useEdiConcentrateStore((s) => s.entities)
  const pagination = useEdiConcentrateStore((s) => s.pagination)
  const isLoading = useEdiConcentrateStore((s) => s.isLoading)
  const { onExport, isExporting } = useChildReportExport(
    EdiConcentrateResource,
    paramsSearch,
    'Concentrado EDI'
  )

  return (
    <div className='space-y-6'>
      <div className='flex flex-wrap items-center justify-between gap-4'>
        <PageHeader
          title='Concentrado EDI'
          description='Prueba EDI anterior y última por niño, ejes con impacto y estado nutricional'
        />
        <ExportExcelButton
          permission='report.edi_concentrate'
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
            title='Sin pruebas EDI para mostrar'
            description='No hay niños con una Prueba EDI calificada que coincidan con los filtros.'
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
                      Elaboró
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
                    <TableHead rowSpan={2} scope='col'>
                      SDG
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Edad corregida
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Edad en meses
                    </TableHead>
                    <TableHead
                      colSpan={2 + impactAxes.length}
                      scope='colgroup'
                      className={GROUP_HEAD}
                    >
                      Prueba anterior
                    </TableHead>
                    <TableHead
                      colSpan={2 + impactAxes.length}
                      scope='colgroup'
                      className={GROUP_HEAD}
                    >
                      Última prueba
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Cuidador
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Teléfono
                    </TableHead>
                    <TableHead colSpan={6} scope='colgroup' className={GROUP_HEAD}>
                      Estado nutricional (última medición)
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Seguimiento y referencias
                    </TableHead>
                    <TableHead rowSpan={2} scope='col'>
                      Próxima evaluación (mes-año)
                    </TableHead>
                  </TableRow>
                  <TableRow>
                    {['anterior', 'última'].flatMap((group) => [
                      <TableHead key={`${group}-date`} scope='col'>
                        Fecha
                      </TableHead>,
                      <TableHead key={`${group}-result`} scope='col'>
                        Calificación
                      </TableHead>,
                      ...impactAxes.map((axis) => (
                        <TableHead
                          key={`${group}-${axis.code}`}
                          scope='col'
                          className='text-center'
                          title={axis.name}
                        >
                          {axis.code}
                        </TableHead>
                      )),
                    ])}
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col'>Peso</TableHead>
                    <TableHead scope='col'>Talla</TableHead>
                    <TableHead scope='col'>Peso/talla</TableHead>
                    <TableHead scope='col'>Peso/edad</TableHead>
                    <TableHead scope='col'>Talla/edad</TableHead>
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
                      <TableCell>{row.evaluator_name ?? '—'}</TableCell>
                      <TableCell>{row.health_center_name ?? '—'}</TableCell>
                      <TableCell className='font-mono tabular-nums whitespace-nowrap'>
                        {displayFormatDate(row.birth_date, 'mediumDate')}
                      </TableCell>
                      <TableCell className='whitespace-nowrap'>{row.age_label}</TableCell>
                      <TableCell className='tabular-nums'>
                        {row.gestational_age_weeks ?? '—'}
                      </TableCell>
                      <TableCell className='whitespace-nowrap'>
                        {row.last_screening?.corrected_age_label ?? '—'}
                      </TableCell>
                      <TableCell className='tabular-nums'>{row.age_months}</TableCell>
                      <ScreeningCells screening={row.previous_screening} impactAxes={impactAxes} />
                      <ScreeningCells screening={row.last_screening} impactAxes={impactAxes} />
                      <TableCell className='whitespace-nowrap'>
                        {row.caregiver_name ?? '—'}
                      </TableCell>
                      <TableCell className='font-mono tabular-nums'>
                        {row.caregiver_phone ?? '—'}
                      </TableCell>
                      <NutritionCells nutrition={row.nutrition} />
                      <TableCell className='min-w-48'>{row.follow_up_summary ?? '—'}</TableCell>
                      <TableCell className='font-mono tabular-nums'>
                        {formatMonthYear(row.next_evaluation)}
                      </TableCell>
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
