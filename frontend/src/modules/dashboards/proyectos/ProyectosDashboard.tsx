import { KpiTile } from '@/shared/components/atoms/KpiTile'
import type {
  IProyectoRow,
  IProyectosPayload,
  IProyectoTableRow,
} from '@/modules/dashboards/proyectos/schema'
import { ProyectosResource } from '@/modules/dashboards/proyectos/services/ProyectosResource'
import { DonutChart } from '@/shared/components/charts/DonutChart'
import { GroupedBarChart } from '@/shared/components/charts/GroupedBarChart'
import type { ResponsiveField } from '@/shared/components/molecules/ResponsiveDataTable'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import {
  ChartCardSkeleton,
  KpiRowSkeleton,
  TableSkeletonRows,
} from '@/shared/components/molecules/DashboardSkeleton'
import { usePermissions } from '@/shared/hooks/usePermissions'
import {
  displayMoney,
  formatMinorUnits,
  moneyToMinorUnits,
  type IMoney,
} from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'
import { Skeleton } from 'boneyard-js/react'
import { TableHead, TableHeader, TableRow, TableCell } from '@/components/ui/table'

function ProyectosKpis({ data }: { data: IProyectosPayload | undefined }) {
  const totals = data?.totals
  const activeCount = totals?.active_count ?? 0
  const goal = moneyToMinorUnits(totals?.goal)
  const raised = moneyToMinorUnits(totals?.raised)
  const pct = totals?.pct ?? 0

  return (
    <div className='mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
      <KpiTile
        swatch='#3F6E8E'
        label='Proyectos activos'
        value={String(activeCount)}
        detail={`${activeCount === 1 ? 'proyecto en' : 'proyectos en'} recaudación`}
      />
      <KpiTile
        swatch='#888D83'
        label='Meta total'
        value={formatMinorUnits(goal)}
        detail='Suma de metas de todos los proyectos'
      />
      <KpiTile
        swatch='#2E6B45'
        label='Recaudado'
        value={formatMinorUnits(raised)}
        detail='Total de aportaciones recibidas'
      />
      <KpiTile
        swatch={pct >= 100 ? '#2E6B45' : pct >= 50 ? '#3F6E8E' : '#C0533C'}
        label='Avance global'
        value={`${pct}%`}
        detail='Porcentaje de la meta total alcanzado'
      />
    </div>
  )
}

function RaisedVsGoalChart({ data }: { data: IProyectosPayload | undefined }) {
  const projects = data?.projects ?? []
  if (projects.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Aún no hay proyectos registrados.
      </p>
    )
  }

  const chartData = projects.map((p) => ({
    label: p.title.length > 20 ? p.title.slice(0, 18) + '…' : p.title,
    recaudado: moneyToMinorUnits(p.raised),
    meta: moneyToMinorUnits(p.goal),
  }))

  return (
    <GroupedBarChart
      data={chartData}
      series={[
        { dataKey: 'recaudado', label: 'Recaudado', color: 'var(--chart-1)' },
        { dataKey: 'meta', label: 'Meta', color: 'var(--chart-4)' },
      ]}
      ariaLabel='Recaudado vs. meta por proyecto comunitario'
      height={280}
    />
  )
}

function ByStatusDonut({ data }: { data: IProyectosPayload | undefined }) {
  const byStatus = data?.by_status ?? []
  if (byStatus.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Sin datos de estado de proyectos.
      </p>
    )
  }

  const donutData = byStatus.map((s) => ({
    key: s.status_key,
    label: s.label,
    value: s.count,
  }))

  return (
    <DonutChart data={donutData} ariaLabel='Distribución de proyectos por estado' height={260} />
  )
}

function buildProjectRows(projects: IProyectoRow[]): IProyectoTableRow[] {
  return projects.map((p) => ({
    id: p.id,
    title: p.title,
    raised: p.raised,
    goal: p.goal,
    pct: p.pct,
    status_label: p.status_label,
  }))
}

function ProjectsTable({ data }: { data: IProyectosPayload | undefined }) {
  const rawProjects = data?.projects ?? []
  if (rawProjects.length === 0) {
    return (
      <p className='py-8 text-center text-[13px] text-[var(--ink-3)]'>
        Aún no hay proyectos comunitarios registrados.
      </p>
    )
  }

  const rows = buildProjectRows(rawProjects)

  return (
    <ResponsiveDataTable
      rows={rows}
      header={
        <TableHeader>
          <TableRow>
            <TableHead scope='col' className='text-left'>
              Proyecto
            </TableHead>
            <TableHead scope='col' className='text-right'>
              Recaudado
            </TableHead>
            <TableHead scope='col' className='text-right'>
              Meta
            </TableHead>
            <TableHead scope='col' className='text-right'>
              Avance
            </TableHead>
            <TableHead scope='col' className='text-left'>
              Estado
            </TableHead>
          </TableRow>
        </TableHeader>
      }
      renderRow={(row) => {
        const title = row.title as string
        const raised = row.raised as IMoney
        const goal = row.goal as IMoney
        const pct = row.pct as number
        const statusLabel = row.status_label as string
        return (
          <TableRow key={row.id}>
            <TableCell className='font-semibold'>{title}</TableCell>
            <TableCell className='text-right tabular-nums'>{displayMoney(raised)}</TableCell>
            <TableCell className='text-right tabular-nums text-[var(--ink-3)]'>
              {displayMoney(goal)}
            </TableCell>
            <TableCell className='text-right tabular-nums'>{pct}%</TableCell>
            <TableCell className='text-[var(--ink-3)]'>{statusLabel}</TableCell>
          </TableRow>
        )
      }}
      renderCardTitle={(row) => row.title as string}
      renderCardFields={(row): ResponsiveField[] => [
        { label: 'Recaudado', value: displayMoney(row.raised as IMoney) },
        { label: 'Meta', value: displayMoney(row.goal as IMoney) },
        { label: 'Avance', value: `${row.pct as number}%` },
        { label: 'Estado', value: row.status_label as string },
      ]}
      getRowKey={(row) => row.id}
    />
  )
}

/**
 * Tablero de Proyectos Comunitarios — avance de financiamiento.
 * Endpoint: GET /projects/funding-summary
 */
export function ProyectosDashboard() {
  const canRead = usePermissions(['read.report_projects', 'report.report_projects'])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboards', 'proyectos'],
    queryFn: () => ProyectosResource.fetch(),
    enabled: canRead,
  })

  if (!canRead) {
    return (
      <div className='py-20 text-center text-[14px] text-[var(--ink-3)]'>
        No tienes acceso a este tablero.
      </div>
    )
  }

  return (
    <div>
      {/* Encabezado */}
      <div className='mb-6'>
        <div className='mb-[7px] text-[10.5px] font-semibold uppercase tracking-[0.15em] text-[var(--ink-4)]'>
          Tableros · Proyectos
        </div>
        <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
          Proyectos <b className='font-semibold text-[var(--green)]'>comunitarios</b>
        </h1>
        <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
          Avance de financiamiento y estado de los proyectos del condominio
        </p>
      </div>

      {isError && (
        <div className='mb-4 rounded-[10px] border border-[#F6DCDC] bg-[#FEF2F2] px-4 py-3 text-[13px] text-[#B91C1C]'>
          Error al cargar los datos. Verifica tu conexión e intenta de nuevo.
        </div>
      )}

      {/* KPIs */}
      {isLoading ? (
        <KpiRowSkeleton count={4} />
      ) : (
        <Skeleton name='proyectos-kpis' loading={false}>
          <ProyectosKpis data={data} />
        </Skeleton>
      )}

      {/* Gráficas */}
      {isLoading ? (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-[1.8fr_1fr]'>
          <ChartCardSkeleton height={280} titleWidth='w-52' />
          <ChartCardSkeleton height={280} titleWidth='w-40' />
        </div>
      ) : (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-[1.8fr_1fr]'>
          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Recaudado vs. meta por proyecto</h2>
            <RaisedVsGoalChart data={data} />
          </div>

          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Distribución por estado</h2>
            <ByStatusDonut data={data} />
          </div>
        </div>
      )}

      {/* Tabla de proyectos */}
      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        <div className='border-b border-[var(--line-2)] px-4 py-3.5'>
          <h2 className='text-[14px] font-semibold'>Detalle de proyectos</h2>
          <p className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
            Avance individual de cada proyecto comunitario
          </p>
        </div>
        <div className='p-4'>
          {isLoading ? <TableSkeletonRows rows={5} cols={5} /> : <ProjectsTable data={data} />}
        </div>
      </div>
    </div>
  )
}
