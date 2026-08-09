import { KpiTile } from '@/shared/components/atoms/KpiTile'
import type { ICashflowAccount, ITesoreriaPayload } from '@/modules/dashboards/tesoreria/schema'
import { TesoreriaResource } from '@/modules/dashboards/tesoreria/services/TesoreriaResource'
import { AreaTrendChart } from '@/shared/components/charts/AreaTrendChart'
import { GroupedBarChart } from '@/shared/components/charts/GroupedBarChart'
import {
  AccountsListSkeleton,
  ChartCardSkeleton,
  KpiRowSkeleton,
} from '@/shared/components/molecules/DashboardSkeleton'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayMoney, formatMinorUnits, moneyToMinorUnits } from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'
import { Skeleton } from 'boneyard-js/react'

const MESES = 12

function TesoreriaKpis({ data }: { data: ITesoreriaPayload | undefined }) {
  const totals = data?.totals
  const totalBalance = moneyToMinorUnits(totals?.total_balance)
  const accountCount = totals?.account_count ?? 0
  const inflow = moneyToMinorUnits(totals?.current_month_inflow)
  const outflow = moneyToMinorUnits(totals?.current_month_outflow)

  return (
    <div className='mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
      <KpiTile
        swatch='#2E6B45'
        label='Saldo total'
        value={formatMinorUnits(totalBalance)}
        detail='Suma de todas las cuentas activas'
      />
      <KpiTile
        swatch='#3F6E8E'
        label='Cuentas activas'
        value={String(accountCount)}
        detail='Cuentas bancarias y cajas registradas'
      />
      <KpiTile
        swatch='#2E6B45'
        label='Entradas del mes'
        value={formatMinorUnits(inflow)}
        detail='Ingresos confirmados en el mes actual'
      />
      <KpiTile
        swatch='#C0533C'
        label='Salidas del mes'
        value={formatMinorUnits(outflow)}
        detail='Egresos no cancelados en el mes actual'
      />
    </div>
  )
}

function SaldoTendenciaChart({ data }: { data: ITesoreriaPayload | undefined }) {
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
    value: moneyToMinorUnits(bucket.running_balance),
  }))

  return (
    <AreaTrendChart
      data={chartData}
      seriesLabel='Saldo acumulado'
      color='var(--chart-1)'
      ariaLabel='Evolución del saldo acumulado del condominio'
      height={260}
    />
  )
}

function InflowOutflowChart({ data }: { data: ITesoreriaPayload | undefined }) {
  const series = data?.series ?? []
  if (series.length === 0) {
    return (
      <p className='py-12 text-center text-[13px] text-[var(--ink-3)]'>
        Sin datos de entradas y salidas.
      </p>
    )
  }

  const chartData = series.map((bucket) => ({
    label: bucket.label,
    entradas: moneyToMinorUnits(bucket.inflow),
    salidas: moneyToMinorUnits(bucket.outflow),
  }))

  return (
    <GroupedBarChart
      data={chartData}
      series={[
        { dataKey: 'entradas', label: 'Entradas', color: 'var(--chart-1)' },
        { dataKey: 'salidas', label: 'Salidas', color: 'var(--chart-3)' },
      ]}
      ariaLabel='Entradas vs. salidas mensuales de tesorería'
      height={260}
    />
  )
}

function AccountsList({ accounts }: { accounts: ICashflowAccount[] }) {
  if (accounts.length === 0) {
    return (
      <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
        Sin cuentas registradas.
      </p>
    )
  }

  const total = accounts.reduce((sum, a) => sum + moneyToMinorUnits(a.current_balance), 0)

  return (
    <>
      {accounts.map((account) => (
        <div
          key={account.id}
          className='flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
        >
          <div className='min-w-0 flex-1 text-[13px] font-semibold'>{account.name}</div>
          <div className='text-[14px] font-semibold tabular-nums'>
            {displayMoney(account.current_balance)}
          </div>
        </div>
      ))}
      <div className='flex items-center justify-between border-t border-border bg-[var(--surface-2,#FAF9F5)] px-4 py-3.5'>
        <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Total
        </span>
        <span className='text-[17px] font-semibold tabular-nums'>{formatMinorUnits(total)}</span>
      </div>
    </>
  )
}

/**
 * Tablero de Tesorería — flujo de caja y saldos de cuentas.
 * Endpoint: GET /treasury/cashflow-series?months=12
 */
export function TesoreriaDashboard() {
  const canRead = usePermissions(['read.report_treasury', 'report.report_treasury'])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboards', 'tesoreria', MESES],
    queryFn: () => TesoreriaResource.fetch(MESES),
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
          Tableros · Tesorería
        </div>
        <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
          Flujo de <b className='font-semibold text-[var(--green)]'>caja</b>
        </h1>
        <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
          Saldos, entradas y salidas de los últimos 12 meses
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
        <Skeleton name='tesoreria-kpis' loading={false}>
          <TesoreriaKpis data={data} />
        </Skeleton>
      )}

      {/* Gráficas */}
      {isLoading ? (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-2'>
          <ChartCardSkeleton height={260} titleWidth='w-56' />
          <ChartCardSkeleton height={260} titleWidth='w-52' />
        </div>
      ) : (
        <div className='mb-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-2'>
          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Evolución del saldo acumulado</h2>
            <SaldoTendenciaChart data={data} />
          </div>

          <div className='overflow-hidden rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            <h2 className='mb-4 text-[14px] font-semibold'>Entradas vs. salidas mensuales</h2>
            <InflowOutflowChart data={data} />
          </div>
        </div>
      )}

      {/* Cuentas */}
      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        <div className='border-b border-[var(--line-2)] px-4 py-3.5'>
          <h2 className='text-[14px] font-semibold'>Cuentas bancarias</h2>
          <p className='mt-0.5 text-[12px] text-[var(--ink-3)]'>Saldo actual por cuenta</p>
        </div>
        {isLoading ? (
          <AccountsListSkeleton rows={4} />
        ) : (
          <AccountsList accounts={data?.accounts ?? []} />
        )}
      </div>
    </div>
  )
}
