import { KpiTile } from '@/shared/components/atoms/KpiTile'
import type { IGastoMonthlyBucket, IGastosPayload } from '@/modules/dashboards/gastos/schema'
import { GastosResource } from '@/modules/dashboards/gastos/services/GastosResource'
import { AreaTrendChart } from '@/shared/components/charts/AreaTrendChart'
import { DonutChart } from '@/shared/components/charts/DonutChart'
import { GroupedBarChart } from '@/shared/components/charts/GroupedBarChart'
import { ChartCardSkeleton, KpiRowSkeleton } from '@/shared/components/molecules/DashboardSkeleton'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { formatMinorUnits, moneyToMinorUnits } from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'
import { Skeleton } from 'boneyard-js/react'

const MESES = 12

function GastosKpis({ data }: { data: IGastosPayload | undefined }) {
  const totals = data?.totals
  const total = moneyToMinorUnits(totals?.total)
  const recurring = moneyToMinorUnits(totals?.recurring)
  const recurringPct = totals?.recurring_pct ?? 0
  const providerCount = totals?.provider_count ?? 0

  return (
    <div className='mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
      <KpiTile
        swatch='#C0533C'
        label='Gasto del periodo'
        value={formatMinorUnits(total)}
        detail='Total de egresos no cancelados en 12 meses'
      />
      <KpiTile
        swatch='#3F6E8E'
        label='Gasto recurrente'
        value={formatMinorUnits(recurring)}
        detail='Egresos ligados a proveedores recurrentes'
      />
      <KpiTile
        swatch='#888D83'
        label='% recurrente'
        value={`${recurringPct}%`}
        detail='Proporción de gasto recurrente vs. total'
      />
      <KpiTile
        swatch='#2E6B45'
        label='Proveedores activos'
        value={String(providerCount)}
        detail={`${providerCount === 1 ? 'proveedor con' : 'proveedores con'} egresos en el periodo`}
      />
    </div>
  )
}

function ByCategoryDonut({ data }: { data: IGastosPayload | undefined }) {
  const byCategory = data?.by_category ?? []
  if (byCategory.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Aún no hay egresos registrados en el periodo.
      </p>
    )
  }

  const donutData = byCategory.map((c) => ({
    key: c.expense_category_id,
    label: c.label,
    value: moneyToMinorUnits(c.amount),
  }))

  return (
    <DonutChart data={donutData} ariaLabel='Distribución de egresos por categoría' height={260} />
  )
}

function ByProviderChart({ data }: { data: IGastosPayload | undefined }) {
  const byProvider = data?.by_provider ?? []
  if (byProvider.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Sin egresos vinculados a proveedores en el periodo.
      </p>
    )
  }

  const chartData = byProvider.map((p) => ({
    label: p.label,
    importe: moneyToMinorUnits(p.amount),
  }))

  return (
    <GroupedBarChart
      data={chartData}
      series={[{ dataKey: 'importe', label: 'Importe' }]}
      ariaLabel='Top proveedores por importe de egreso'
      height={260}
    />
  )
}

function MonthlyTrendChart({ data }: { data: IGastosPayload | undefined }) {
  const monthly = data?.monthly ?? []
  if (monthly.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Aún no hay datos de tendencia mensual.
      </p>
    )
  }

  const chartData = monthly.map((bucket: IGastoMonthlyBucket) => ({
    label: bucket.label,
    value: moneyToMinorUnits(bucket.amount),
  }))

  return (
    <AreaTrendChart
      data={chartData}
      seriesLabel='Egresos'
      color='var(--chart-3)'
      ariaLabel='Tendencia mensual de egresos'
      height={260}
    />
  )
}

/**
 * Tablero de Gastos — desglose de egresos por categoría y proveedor.
 * Endpoint: GET /treasury/expenses-breakdown?months=12
 */
export function GastosDashboard() {
  const canRead = usePermissions(['read.report_expenses', 'report.report_expenses'])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboards', 'gastos', MESES],
    queryFn: () => GastosResource.fetch(MESES),
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
          Tableros · Finanzas
        </div>
        <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
          Desglose de <b className='font-semibold text-[var(--green)]'>gastos</b>
        </h1>
        <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
          Egresos por categoría, proveedor y tendencia mensual (últimos 12 meses)
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
        <Skeleton name='gastos-kpis' loading={false}>
          <GastosKpis data={data} />
        </Skeleton>
      )}

      {/* Gráficas superiores */}
      {isLoading ? (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-[1fr_1.4fr]'>
          <ChartCardSkeleton height={260} titleWidth='w-28' />
          <ChartCardSkeleton height={260} titleWidth='w-32' />
        </div>
      ) : (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-[1fr_1.4fr]'>
          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Por categoría</h2>
            <ByCategoryDonut data={data} />
          </div>

          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Por proveedor</h2>
            <ByProviderChart data={data} />
          </div>
        </div>
      )}

      {/* Tendencia mensual */}
      {isLoading ? (
        <ChartCardSkeleton height={260} titleWidth='w-56' />
      ) : (
        <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <h2 className='mb-4 text-[14px] font-semibold'>Tendencia mensual de egresos</h2>
          <MonthlyTrendChart data={data} />
        </div>
      )}
    </div>
  )
}
