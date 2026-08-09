import { KpiTile } from '@/shared/components/atoms/KpiTile'
import type {
  ICobranzaPayload,
  ITopDebtor,
  ITopDebtorRow,
} from '@/modules/dashboards/cobranza/schema'
import { CobranzaResource } from '@/modules/dashboards/cobranza/services/CobranzaResource'
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

function CobranzaKpis({ data }: { data: ICobranzaPayload | undefined }) {
  const totals = data?.totals
  const outstanding = moneyToMinorUnits(totals?.total_outstanding)
  const rate = totals?.collection_rate_pct ?? 0
  const units = totals?.units_with_debt ?? 0
  const avgDays = totals?.avg_days_overdue ?? 0

  return (
    <div className='mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
      <KpiTile
        swatch='#C0533C'
        label='Cartera vencida'
        value={formatMinorUnits(outstanding)}
        detail='Total pendiente de cobro'
      />
      <KpiTile
        swatch='#2E6B45'
        label='Tasa de cobro'
        value={`${rate}%`}
        detail='Cargos cobrados del mes vs. emitidos'
      />
      <KpiTile
        swatch='#888D83'
        label='Unidades morosas'
        value={String(units)}
        detail={`${units === 1 ? 'unidad con' : 'unidades con'} saldo pendiente`}
      />
      <KpiTile
        swatch='#3F6E8E'
        label='Antigüedad promedio'
        value={`${avgDays} días`}
        detail='Promedio de días de atraso por cargo'
      />
    </div>
  )
}

function AgingBucketsChart({ data }: { data: ICobranzaPayload | undefined }) {
  const buckets = data?.buckets
  if (!buckets) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Aún no hay datos de cartera vencida.
      </p>
    )
  }

  const chartData = [
    { label: 'Al corriente', importe: moneyToMinorUnits(buckets.current) },
    { label: '1–30 días', importe: moneyToMinorUnits(buckets.d1_30) },
    { label: '31–60 días', importe: moneyToMinorUnits(buckets.d31_60) },
    { label: '61–90 días', importe: moneyToMinorUnits(buckets.d61_90) },
    { label: '+90 días', importe: moneyToMinorUnits(buckets.d90_plus) },
  ]

  return (
    <GroupedBarChart
      data={chartData}
      series={[{ dataKey: 'importe', label: 'Importe pendiente' }]}
      ariaLabel='Antigüedad de cartera por tramo de días'
      height={260}
    />
  )
}

function ByStatusDonut({ data }: { data: ICobranzaPayload | undefined }) {
  const byStatus = data?.by_status ?? []
  if (byStatus.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Sin datos de estado de cartera.
      </p>
    )
  }

  const donutData = byStatus.map((s) => ({
    key: s.status_key,
    label: s.label,
    value: moneyToMinorUnits(s.amount),
  }))

  return (
    <DonutChart
      data={donutData}
      ariaLabel='Distribución de cartera por estado de cargo'
      height={260}
    />
  )
}

function buildDebtorRows(debtors: ITopDebtor[]): ITopDebtorRow[] {
  return debtors.map((d, i) => ({
    id: `debtor-${i}`,
    unit_label: d.unit_label,
    outstanding: d.outstanding,
    charge_count: d.charge_count,
  }))
}

function TopDebtorsTable({ data }: { data: ICobranzaPayload | undefined }) {
  const rawDebtors = data?.top_debtors ?? []
  if (rawDebtors.length === 0) {
    return (
      <p className='py-8 text-center text-[13px] text-[var(--ink-3)]'>
        Sin unidades con saldo pendiente. ¡Excelente gestión!
      </p>
    )
  }

  const rows = buildDebtorRows(rawDebtors)

  return (
    <ResponsiveDataTable
      rows={rows}
      header={
        <TableHeader>
          <TableRow>
            <TableHead scope='col' className='text-left'>
              Unidad
            </TableHead>
            <TableHead scope='col' className='text-right'>
              Pendiente
            </TableHead>
            <TableHead scope='col' className='text-right'>
              Cargos
            </TableHead>
          </TableRow>
        </TableHeader>
      }
      renderRow={(row) => {
        const unitLabel = row.unit_label as string
        const outstanding = row.outstanding as IMoney
        const charges = row.charge_count as number
        return (
          <TableRow key={row.id}>
            <TableCell className='font-semibold'>{unitLabel}</TableCell>
            <TableCell className='text-right tabular-nums'>{displayMoney(outstanding)}</TableCell>
            <TableCell className='text-right text-[var(--ink-3)]'>{charges}</TableCell>
          </TableRow>
        )
      }}
      renderCardTitle={(row) => row.unit_label as string}
      renderCardFields={(row): ResponsiveField[] => [
        { label: 'Pendiente', value: displayMoney(row.outstanding as IMoney) },
        { label: 'Cargos', value: String(row.charge_count as number) },
      ]}
      getRowKey={(row) => row.id}
    />
  )
}

/**
 * Tablero de Cobranza — aging y morosidad del cluster.
 * Endpoint: GET /collections/aging
 */
export function CobranzaDashboard() {
  const canRead = usePermissions(['read.report_collections', 'report.report_collections'])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboards', 'cobranza'],
    queryFn: () => CobranzaResource.fetch(),
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
          Tableros · Cobranza
        </div>
        <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
          Cartera <b className='font-semibold text-[var(--green)]'>vencida</b>
        </h1>
        <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
          Aging y morosidad actual del condominio
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
        <Skeleton name='cobranza-kpis' loading={false}>
          <CobranzaKpis data={data} />
        </Skeleton>
      )}

      {/* Gráficas */}
      {isLoading ? (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-[1.6fr_1fr]'>
          <ChartCardSkeleton height={260} titleWidth='w-44' />
          <ChartCardSkeleton height={260} titleWidth='w-36' />
        </div>
      ) : (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-[1.6fr_1fr]'>
          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Antigüedad de cartera</h2>
            <AgingBucketsChart data={data} />
          </div>

          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Por estado de cargo</h2>
            <ByStatusDonut data={data} />
          </div>
        </div>
      )}

      {/* Tabla de principales deudores */}
      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        <div className='border-b border-[var(--line-2)] px-4 py-3.5'>
          <h2 className='text-[14px] font-semibold'>Principales deudores</h2>
          <p className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
            Top 10 unidades con mayor saldo pendiente
          </p>
        </div>
        <div className='p-4'>
          {isLoading ? <TableSkeletonRows rows={5} cols={3} /> : <TopDebtorsTable data={data} />}
        </div>
      </div>
    </div>
  )
}
