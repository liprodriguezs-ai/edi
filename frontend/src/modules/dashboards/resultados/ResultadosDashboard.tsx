import { KpiTile } from '@/shared/components/atoms/KpiTile'
import type { IResultadosPayload } from '@/modules/dashboards/resultados/schema'
import { ResultadosResource } from '@/modules/dashboards/resultados/services/ResultadosResource'
import { LineBarComboChart } from '@/shared/components/charts/LineBarComboChart'
import { ChartCardSkeleton, KpiRowSkeleton } from '@/shared/components/molecules/DashboardSkeleton'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { formatMinorUnits, moneyToMinorUnits } from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'
import { Skeleton } from 'boneyard-js/react'

const MESES = 12

function ResultadosKpis({ data }: { data: IResultadosPayload | undefined }) {
  const totals = data?.totals
  const income = moneyToMinorUnits(totals?.income)
  const expense = moneyToMinorUnits(totals?.expense)
  const net = moneyToMinorUnits(totals?.net)
  const margin = totals?.margin_pct ?? 0

  return (
    <div className='mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
      <KpiTile
        swatch='#2E6B45'
        label='Ingresos (12 meses)'
        value={formatMinorUnits(income)}
        detail='Pagos confirmados del periodo'
      />
      <KpiTile
        swatch='#C0533C'
        label='Egresos (12 meses)'
        value={formatMinorUnits(expense)}
        detail='Gastos no cancelados del periodo'
      />
      <KpiTile
        swatch={net >= 0 ? '#3F6E8E' : '#C0533C'}
        label='Neto del periodo'
        value={`${net >= 0 ? '+' : ''}${formatMinorUnits(net)}`}
        detail='Ingresos menos egresos acumulados'
      />
      <KpiTile
        swatch='#888D83'
        label='Margen neto'
        value={`${margin}%`}
        detail='Porcentaje de ingresos que quedan como utilidad'
      />
    </div>
  )
}

function ResultadosChart({ data }: { data: IResultadosPayload | undefined }) {
  const series = data?.series ?? []
  if (series.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Aún no hay datos en el periodo.
      </p>
    )
  }

  const chartData = series.map((bucket) => ({
    label: bucket.label,
    bar: moneyToMinorUnits(bucket.income),
    line: moneyToMinorUnits(bucket.net),
  }))

  return (
    <LineBarComboChart
      data={chartData}
      barLabel='Ingresos'
      lineLabel='Neto'
      ariaLabel='Gráfica de ingresos vs neto mensual'
      height={300}
    />
  )
}

/**
 * Tablero de Estado de Resultados (P&L).
 * Muestra ingresos, egresos, neto y margen del cluster en los últimos 12 meses.
 * Endpoint: GET /treasury/income-expense-series?months=12
 */
export function ResultadosDashboard() {
  const canRead = usePermissions(['read.report_pnl', 'report.report_pnl'])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboards', 'resultados', MESES],
    queryFn: () => ResultadosResource.fetch(MESES),
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
          Estado de <b className='font-semibold text-[var(--green)]'>resultados</b>
        </h1>
        <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
          Ingresos, egresos y utilidad neta de los últimos 12 meses
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
        <Skeleton name='resultados-kpis' loading={false}>
          <ResultadosKpis data={data} />
        </Skeleton>
      )}

      {/* Gráfica principal */}
      {isLoading ? (
        <ChartCardSkeleton height={300} titleWidth='w-52' />
      ) : (
        <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <h2 className='mb-4 text-[14px] font-semibold'>Ingresos vs. neto mensual</h2>
          <ResultadosChart data={data} />
        </div>
      )}
    </div>
  )
}
