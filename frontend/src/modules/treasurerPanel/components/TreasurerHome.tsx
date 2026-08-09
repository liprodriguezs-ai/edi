import { KpiTile } from '@/shared/components/atoms/KpiTile'
import { StatusPill, type PillTone } from '@/shared/components/atoms/StatusPill'
import { ExpenseResource } from '@/modules/expense/services/ExpenseResource'
import { PAYMENT_STATUS, type IPayment } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { sessionStore } from '@/shared/auth/sessionStore'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import {
  displayMoney,
  formatMinorUnits,
  moneyToMinorUnits,
  type IMoney,
} from '@/shared/utils/money'
import { displayInitials } from '@/shared/utils/displayInitials'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, Landmark, Plus, Wallet } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useStore } from 'zustand'

const BTN =
  'inline-flex items-center gap-1.5 rounded-full px-[18px] py-[9px] text-[13px] font-semibold whitespace-nowrap transition-colors'
const BTN_PRIMARY = `${BTN} bg-[var(--green-deep)] text-white hover:bg-[var(--nav-2)]`
const BTN_GHOST = `${BTN} border border-border bg-card font-medium text-foreground hover:border-[var(--ink-3)]`

const TH =
  'border-b border-border bg-[var(--surface-2,#FAF9F5)] px-4 py-2.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--ink-3)]'
const TD = 'border-b border-[var(--line-2)] px-4 py-[11px] text-[13px] text-[var(--ink-2)]'

const MONTH_NAMES = [
  'Enero',
  'Febrero',
  'Marzo',
  'Abril',
  'Mayo',
  'Junio',
  'Julio',
  'Agosto',
  'Septiembre',
  'Octubre',
  'Noviembre',
  'Diciembre',
]

function UnitAvatar({ initials }: { initials: string }) {
  return (
    <span className='grid size-8 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[11px] font-semibold tracking-[0.02em] text-[var(--green)]'>
      {initials}
    </span>
  )
}

function paymentTone(payment: IPayment): PillTone {
  switch (payment.payment_status_id) {
    case PAYMENT_STATUS.CONFIRMED:
      return 'g'
    case PAYMENT_STATUS.PENDING_VALIDATION:
      return 'y'
    default:
      return 'r'
  }
}

interface DelinquencyData {
  units_with_debt: number
  total_outstanding: IMoney
}

function DelinquencyBanner({ data }: { data: DelinquencyData }) {
  if (data.units_with_debt === 0) return null
  const unitLabel = data.units_with_debt === 1 ? 'unidad' : 'unidades'
  return (
    <div className='mb-[18px] flex flex-col gap-3 rounded-[14px] border border-border bg-card p-4 shadow-sm sm:flex-row sm:items-center sm:gap-3.5'>
      <div className='flex min-w-0 flex-1 items-center gap-3'>
        <span className='grid size-[34px] flex-none place-items-center rounded-lg bg-[#F6E2DC] text-[#C0533C]'>
          <AlertCircle className='size-[18px]' />
        </span>
        <div className='min-w-0 text-[13.5px] leading-relaxed'>
          <b className='font-semibold'>
            {data.units_with_debt} {unitLabel} con saldo pendiente.
          </b>{' '}
          <span className='text-[var(--ink-3)]'>
            Adeudo total {displayMoney(data.total_outstanding)} — envía un recordatorio antes de que
            se acumule.
          </span>
        </div>
      </div>
      <Link
        to='/delinquency'
        className={`${BTN_GHOST} w-full flex-none justify-center px-[13px] py-2 text-[12.5px] sm:w-auto`}
      >
        Ver morosidad
      </Link>
    </div>
  )
}

function PaymentRow({ payment }: { payment: IPayment }) {
  const initials = displayInitials(payment.payer_user?.name ?? payment.unit?.number ?? '?')
  return (
    <tr className='transition-colors last:[&>td]:border-b-0 hover:bg-[var(--surface-2,#FAF9F5)]'>
      <td className={TD}>
        <div className='flex items-center gap-2.5'>
          <UnitAvatar initials={initials} />
          <span>
            <span className='font-semibold text-foreground'>
              {payment.unit?.full_identifier ?? payment.unit?.number ?? '—'}
            </span>{' '}
            <span className='text-xs text-[var(--ink-3)]'>{payment.payer_user?.name ?? ''}</span>
          </span>
        </div>
      </td>
      <td className={`${TD} text-right tabular-nums`}>{displayMoney(payment.total_amount)}</td>
      <td className={TD}>{displayFormatDate(payment.paid_at, 'mediumDate')}</td>
      <td className={TD}>
        <StatusPill tone={paymentTone(payment)}>{payment.payment_status?.name ?? '—'}</StatusPill>
      </td>
    </tr>
  )
}

function PaymentCardRow({ payment }: { payment: IPayment }) {
  const initials = displayInitials(payment.payer_user?.name ?? payment.unit?.number ?? '?')
  return (
    <li className='border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'>
      <div className='flex items-center justify-between gap-3'>
        <div className='flex min-w-0 items-center gap-2.5'>
          <UnitAvatar initials={initials} />
          <span className='min-w-0'>
            <span className='block truncate font-semibold text-foreground'>
              {payment.unit?.full_identifier ?? payment.unit?.number ?? '—'}
            </span>
            {payment.payer_user?.name && (
              <span className='block truncate text-xs text-[var(--ink-3)]'>
                {payment.payer_user.name}
              </span>
            )}
          </span>
        </div>
        <StatusPill tone={paymentTone(payment)}>{payment.payment_status?.name ?? '—'}</StatusPill>
      </div>
      <div className='mt-2 flex items-center justify-between text-[12.5px]'>
        <span className='text-[var(--ink-3)]'>
          {displayFormatDate(payment.paid_at, 'mediumDate')}
        </span>
        <span className='font-semibold tabular-nums text-foreground'>
          {displayMoney(payment.total_amount)}
        </span>
      </div>
    </li>
  )
}

function RecentPaymentsPanel({ payments, canRead }: { payments: IPayment[]; canRead: boolean }) {
  return (
    <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
      <div className='flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3.5'>
        <h3 className='text-sm font-semibold'>Pagos recientes</h3>
        {canRead && (
          <Link
            to='/payment'
            className='text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
          >
            Ver cobranza →
          </Link>
        )}
      </div>
      {!canRead || payments.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          {canRead ? 'Aún no hay pagos registrados este periodo.' : 'Sin acceso a cobranza.'}
        </p>
      ) : (
        <>
          {/* Desktop (md+): tabla */}
          <div className='hidden md:block'>
            <table className='w-full border-collapse'>
              <thead>
                <tr>
                  <th scope='col' className={`${TH} text-left`}>
                    Unidad
                  </th>
                  <th scope='col' className={`${TH} text-right`}>
                    Monto
                  </th>
                  <th scope='col' className={`${TH} text-left`}>
                    Fecha
                  </th>
                  <th scope='col' className={`${TH} text-left`}>
                    Estado
                  </th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment) => (
                  <PaymentRow key={payment.id} payment={payment} />
                ))}
              </tbody>
            </table>
          </div>
          {/* Mobile (< md): filas apiladas, sin scroll horizontal */}
          <ul className='md:hidden' role='list'>
            {payments.map((payment) => (
              <PaymentCardRow key={payment.id} payment={payment} />
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

interface BankAccountBalance {
  name: string
  current_balance: IMoney
}

function AccountsPanel({
  accounts,
  canRead,
  total,
}: {
  accounts: BankAccountBalance[]
  canRead: boolean
  total: number
}) {
  return (
    <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
      <div className='flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3.5'>
        <h3 className='text-sm font-semibold'>Cuentas</h3>
        {canRead && (
          <Link
            to='/bank-account'
            className='text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
          >
            Tesorería →
          </Link>
        )}
      </div>
      {!canRead || accounts.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          {canRead ? 'Sin cuentas registradas.' : 'Sin acceso a cuentas.'}
        </p>
      ) : (
        <>
          {accounts.map((account) => (
            <div
              key={account.name}
              className='flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
            >
              <span className='grid size-8 flex-none place-items-center rounded-md bg-[var(--surface-3,#F3F2EC)] text-[var(--green-deep)]'>
                {account.name.toLowerCase().includes('caja') ? (
                  <Wallet className='size-4' />
                ) : (
                  <Landmark className='size-4' />
                )}
              </span>
              <div className='min-w-0 text-[13px] font-semibold'>{account.name}</div>
              <div className='ml-auto text-[15px] font-semibold tabular-nums'>
                {displayMoney(account.current_balance)}
              </div>
            </div>
          ))}
          <div className='flex items-center justify-between border-t border-border bg-[var(--surface-2,#FAF9F5)] px-4 py-3.5'>
            <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
              Total estimado
            </span>
            <span className='text-[17px] font-semibold tabular-nums'>
              {formatMinorUnits(total)}
            </span>
          </div>
        </>
      )}
    </div>
  )
}

interface SummaryData {
  total_income?: IMoney
  total_expenses?: IMoney
  net_balance?: IMoney
  expenses_by_category?: { category: string; total: IMoney }[]
}

function IncomeVsExpensesCard({
  summary,
  periodLabel,
}: {
  summary: SummaryData | undefined
  periodLabel: string
}) {
  const totalIncome = moneyToMinorUnits(summary?.total_income)
  const totalExpenses = moneyToMinorUnits(summary?.total_expenses)
  const netBalance = moneyToMinorUnits(summary?.net_balance)
  const max = Math.max(totalIncome, totalExpenses, 1)
  const rows = [
    { label: 'Ingresos', amount: totalIncome, color: '#2E6B45' },
    { label: 'Egresos', amount: totalExpenses, color: '#C0533C' },
  ]
  return (
    <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
      <h3 className='mb-3 text-sm font-semibold'>Ingresos vs egresos · {periodLabel}</h3>
      {rows.map((row) => (
        <div key={row.label} className='mb-3 last:mb-0'>
          <div className='mb-1 flex justify-between text-[12.5px]'>
            <span className='text-[var(--ink-2)]'>{row.label}</span>
            <b className='font-semibold tabular-nums'>{formatMinorUnits(row.amount)}</b>
          </div>
          <div className='h-2 overflow-hidden rounded-full bg-[var(--line-2)]'>
            <i
              className='block h-full rounded-full'
              style={{
                width: `${Math.round((row.amount / max) * 100)}%`,
                backgroundColor: row.color,
              }}
            />
          </div>
        </div>
      ))}
      <div className='mt-3 flex justify-between border-t border-[var(--line-2)] pt-2.5 text-[13px] font-semibold'>
        <span>Neto</span>
        <span
          className={`tabular-nums ${netBalance >= 0 ? 'text-[var(--green)]' : 'text-[#C0533C]'}`}
        >
          {netBalance >= 0 ? '+' : ''}
          {formatMinorUnits(netBalance)}
        </span>
      </div>
    </div>
  )
}

function ExpensesByCategoryCard({ summary }: { summary: SummaryData | undefined }) {
  const rows = summary?.expenses_by_category ?? []
  const totalExpenses = rows.reduce((sum, row) => sum + moneyToMinorUnits(row.total), 0)
  return (
    <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
      <h3 className='mb-3 text-sm font-semibold'>Egresos por categoría</h3>
      {rows.length === 0 ? (
        <p className='py-6 text-center text-[13px] text-[var(--ink-3)]'>
          Sin egresos este periodo.
        </p>
      ) : (
        rows.map((row) => {
          const rowMinor = moneyToMinorUnits(row.total)
          const pct = totalExpenses > 0 ? Math.round((rowMinor / totalExpenses) * 100) : 0
          return (
            <div key={row.category} className='mb-3 last:mb-0'>
              <div className='mb-1 flex justify-between gap-3 text-[12.5px]'>
                <span className='min-w-0 truncate text-[var(--ink-2)]'>{row.category}</span>
                <span className='flex-none text-[var(--ink-3)]'>
                  <b className='font-semibold text-foreground'>{displayMoney(row.total)}</b> · {pct}
                  %
                </span>
              </div>
              <div className='h-2 overflow-hidden rounded-full bg-[var(--line-2)]'>
                <i
                  className='block h-full rounded-full bg-[var(--green)]'
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          )
        })
      )}
    </div>
  )
}

function buildNetLabel(netBalance: number, totalIncome: number, totalExpenses: number): string {
  const sign = netBalance >= 0 ? '+' : ''
  return `${sign}${formatMinorUnits(netBalance)} — Ing. ${formatMinorUnits(totalIncome)} · Eg. ${formatMinorUnits(totalExpenses)}`
}

interface KpiSectionProps {
  summary: SummaryData | undefined
  delinquency: DelinquencyData | undefined
  accounts: BankAccountBalance[]
  totalAccounts: number
  canReadPayments: boolean
  canReadAccounts: boolean
  canViewFinance: boolean
}

function KpiSection({
  summary,
  delinquency,
  accounts,
  totalAccounts,
  canReadPayments,
  canReadAccounts,
  canViewFinance,
}: KpiSectionProps) {
  const totalIncome = moneyToMinorUnits(summary?.total_income)
  const totalExpenses = moneyToMinorUnits(summary?.total_expenses)
  const netBalance = moneyToMinorUnits(summary?.net_balance)
  const accountsDetail =
    accounts.length > 0 ? accounts.map((a) => a.name).join(' · ') : 'Cuentas del condominio'
  const delinquencyUnits = delinquency?.units_with_debt ?? 0
  const delinquencyTotal = moneyToMinorUnits(delinquency?.total_outstanding)
  // Las tarjetas de finanzas del clúster (ingresos/neto) solo para roles de
  // cobranza/tesorería; un residente no debe ver totales del condominio.
  const gridCols = canViewFinance ? 'lg:grid-cols-4' : 'lg:grid-cols-2'
  return (
    <div className={`mb-[18px] grid grid-cols-1 gap-3.5 sm:grid-cols-2 ${gridCols}`}>
      {canViewFinance && (
        <KpiTile
          swatch='#2E6B45'
          label='Ingresos del mes'
          value={formatMinorUnits(totalIncome)}
          detail='Pagos confirmados del periodo'
        />
      )}
      <KpiTile
        swatch='#3F6E8E'
        label='Saldo en cuentas'
        value={canReadAccounts ? formatMinorUnits(totalAccounts) : '—'}
        detail={accountsDetail}
      />
      <KpiTile
        swatch='#C0533C'
        label='Adeudo total'
        value={canReadPayments ? formatMinorUnits(delinquencyTotal) : '—'}
        detail={`${delinquencyUnits} ${delinquencyUnits === 1 ? 'unidad' : 'unidades'} con saldo`}
      />
      {canViewFinance && (
        <KpiTile
          swatch='#888D83'
          label='Neto del mes'
          value={buildNetLabel(netBalance, 0, 0).split(' — ')[0]}
          detail={`Ingresos ${formatMinorUnits(totalIncome)} · Egresos ${formatMinorUnits(totalExpenses)}`}
        />
      )}
    </div>
  )
}

/**
 * Home del Panel del Tesorero (pantalla "Inicio" del mock v3) con datos reales:
 * resumen del mes (treasury/public-summary), morosidad, pagos recientes y cuentas.
 * Incluye la sección de transparencia (ingresos vs egresos + categorías, §9.5).
 */
export function TreasurerHome() {
  const tenantName = useStore(sessionStore, (s) => s.profile?.tenant?.name)
  const canReadPayments = usePermissions('read.payment')
  const canReadAccounts = usePermissions('read.bank_account')
  // Acceso a finanzas del clúster (cobranza o tesorería). Un residente sin estos
  // permisos no debe ver ingresos/neto del mes ni la transparencia de egresos.
  const canViewFinance = canReadPayments || canReadAccounts

  const now = new Date()
  const month = now.getMonth()
  const year = now.getFullYear()
  const periodLabel = `${MONTH_NAMES[month]} ${year}`

  const { data: summary } = useQuery({
    queryKey: ['treasury', 'summary', year, month + 1],
    queryFn: () => ExpenseResource.publicSummary(year, month + 1),
  })

  const { data: delinquency } = useQuery({
    queryKey: ['delinquency'],
    queryFn: () => PaymentResource.delinquency(),
    enabled: canReadPayments,
  })

  const { data: recentPayments = [] } = useQuery({
    queryKey: ['payments', 'recent'],
    queryFn: async () => {
      const { data } = await PaymentResource.search({
        limit: 5,
        sort: [{ field: 'paid_at', direction: 'desc' }],
        includes: [
          { relation: 'unit' },
          { relation: 'payer_user' },
          { relation: 'payment_status' },
        ],
      })
      return data
    },
    enabled: canReadPayments,
  })

  const accounts = summary?.bank_account_balances ?? []
  const totalAccounts = accounts.reduce((sum, a) => sum + moneyToMinorUnits(a.current_balance), 0)
  const displayName = tenantName ?? APP_CONFIG.brandName

  return (
    <div>
      {/* page header */}
      <div className='mb-6 flex flex-wrap items-start justify-between gap-5'>
        <div>
          <div className='mb-[7px] text-[10.5px] font-semibold uppercase tracking-[0.15em] text-[var(--ink-4)]'>
            {displayName} · {periodLabel}
          </div>
          <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
            Resumen del <b className='font-semibold text-[var(--green)]'>mes</b>
          </h1>
          <div className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
            Esto es lo que pasa hoy en tu condominio
          </div>
        </div>
        <div className='flex items-center gap-2'>
          {canReadPayments && (
            <Link to='/payment' className={BTN_PRIMARY}>
              <Plus className='size-[15px]' />
              Registrar pago
            </Link>
          )}
        </div>
      </div>

      {/* banner de morosidad — PendingPackagesWidget moved to DashboardPage for all roles */}
      {canReadPayments && delinquency && <DelinquencyBanner data={delinquency} />}

      {/* KPIs */}
      <KpiSection
        summary={summary}
        delinquency={delinquency}
        accounts={accounts}
        totalAccounts={totalAccounts}
        canReadPayments={canReadPayments}
        canReadAccounts={canReadAccounts}
        canViewFinance={canViewFinance}
      />

      {/* two columns: payments + accounts */}
      <div className='grid grid-cols-1 gap-3.5 lg:grid-cols-[1.7fr_1fr]'>
        <RecentPaymentsPanel payments={recentPayments} canRead={canReadPayments} />
        <AccountsPanel accounts={accounts} canRead={canReadAccounts} total={totalAccounts} />
      </div>

      {/* transparencia: ingresos vs egresos + categorías (§9.5) — solo finanzas del clúster */}
      {canViewFinance && (
        <div className='mt-3.5 grid grid-cols-1 gap-3.5 lg:grid-cols-2'>
          <IncomeVsExpensesCard summary={summary} periodLabel={periodLabel} />
          <ExpensesByCategoryCard summary={summary} />
        </div>
      )}
    </div>
  )
}
