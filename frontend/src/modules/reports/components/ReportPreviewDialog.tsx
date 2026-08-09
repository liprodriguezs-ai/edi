import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  ReportsResource,
  type AccountBalanceRow,
  type AuditLogRow,
  type DelinquencyReport,
  type IncomeStatementReport,
  type PaginatedResult,
} from '@/modules/reports/services/ReportsResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { displayFormatDate, formatDateForInput } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'

interface ReportPreviewDialogProps {
  reportKey: string | null
  label: string
  onClose: () => void
}

/**
 * Vista previa read-only de los reportes con endpoint de consulta (§15.2):
 * morosidad, estado de resultados, saldos de cuentas y auditoría.
 */
export function ReportPreviewDialog({ reportKey, label, onClose }: ReportPreviewDialogProps) {
  const open = reportKey !== null

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className='max-w-3xl' aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>Vista previa · {label}</DialogTitle>
        </DialogHeader>
        {reportKey === 'delinquency' && <DelinquencyPreview />}
        {reportKey === 'income_statement' && <IncomeStatementPreview />}
        {reportKey === 'account_balances' && <AccountBalancesPreview />}
        {reportKey === 'audit_log' && <AuditLogPreview />}
      </DialogContent>
    </Dialog>
  )
}

function PreviewState({
  isLoading,
  error,
  empty,
  children,
}: {
  isLoading: boolean
  error: unknown
  empty: boolean
  children: React.ReactNode
}) {
  if (isLoading) {
    return <p className='py-8 text-center text-[13px] text-[var(--ink-3)]'>Cargando…</p>
  }
  if (error) {
    return (
      <p className='py-8 text-center text-[13px] text-destructive'>
        {error instanceof ApiError
          ? formatApiErrorMessage(error).title
          : 'No se pudo cargar la vista previa'}
      </p>
    )
  }
  if (empty) {
    return (
      <p className='py-8 text-center text-[13px] text-[var(--ink-3)]'>Sin datos para mostrar.</p>
    )
  }
  return <>{children}</>
}

function DelinquencyPreview() {
  const { data, isLoading, error } = useQuery<DelinquencyReport>({
    queryKey: ['reports', 'preview', 'delinquency'],
    queryFn: () => ReportsResource.previewDelinquency(),
  })

  return (
    <PreviewState isLoading={isLoading} error={error} empty={!data || data.units.length === 0}>
      <div className='space-y-3'>
        <div className='flex flex-wrap gap-4 text-[13px]'>
          <span>
            <span className='text-[var(--ink-3)]'>Unidades morosas:</span>{' '}
            <strong>{data?.unit_count ?? 0}</strong>
          </span>
          <span>
            <span className='text-[var(--ink-3)]'>Total vencido:</span>{' '}
            <strong>{displayMoney(data?.total_overdue_amount)}</strong>
          </span>
        </div>
        <div className='max-h-[55vh] overflow-y-auto rounded-[12px] border border-border'>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unidad</TableHead>
                <TableHead className='text-right'>Cargos vencidos</TableHead>
                <TableHead className='text-right'>Monto vencido</TableHead>
                <TableHead>Más antiguo</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data?.units.map((unit) => (
                <TableRow key={unit.unit_id ?? unit.unit_number}>
                  <TableCell className='font-medium'>
                    {unit.unit_full_identifier ?? unit.unit_number ?? '—'}
                  </TableCell>
                  <TableCell className='text-right tabular-nums'>{unit.overdue_charges}</TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {displayMoney(unit.total_overdue_amount)}
                  </TableCell>
                  <TableCell>
                    {unit.oldest_due_date
                      ? displayFormatDate(unit.oldest_due_date, 'mediumDate')
                      : '—'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </div>
    </PreviewState>
  )
}

function IncomeStatementPreview() {
  const defaults = useMemo(() => {
    const now = new Date()
    const firstOfMonth = new Date(now.getFullYear(), now.getMonth(), 1)
    return {
      start: formatDateForInput(firstOfMonth.toISOString()),
      end: formatDateForInput(now.toISOString()),
    }
  }, [])

  const [periodStart, setPeriodStart] = useState(defaults.start)
  const [periodEnd, setPeriodEnd] = useState(defaults.end)

  const { data, isLoading, error } = useQuery<IncomeStatementReport>({
    queryKey: ['reports', 'preview', 'income_statement', periodStart, periodEnd],
    queryFn: () => ReportsResource.previewIncomeStatement(periodStart, periodEnd),
    enabled: Boolean(periodStart && periodEnd),
  })

  return (
    <div className='space-y-4'>
      <div className='flex flex-wrap items-end gap-3'>
        <label className='text-[12.5px] text-[var(--ink-3)]'>
          Desde
          <Input
            type='date'
            value={periodStart}
            onChange={(e) => setPeriodStart(e.target.value)}
            className='mt-1 w-[160px]'
          />
        </label>
        <label className='text-[12.5px] text-[var(--ink-3)]'>
          Hasta
          <Input
            type='date'
            value={periodEnd}
            onChange={(e) => setPeriodEnd(e.target.value)}
            className='mt-1 w-[160px]'
          />
        </label>
      </div>
      <PreviewState isLoading={isLoading} error={error} empty={!data}>
        <div className='grid grid-cols-1 gap-3 sm:grid-cols-3'>
          <div className='rounded-[12px] border border-border p-4'>
            <p className='text-[12px] text-[var(--ink-3)]'>Ingresos</p>
            <p className='mt-1 text-[18px] font-semibold text-[var(--green-deep)]'>
              {displayMoney(data?.total_income)}
            </p>
          </div>
          <div className='rounded-[12px] border border-border p-4'>
            <p className='text-[12px] text-[var(--ink-3)]'>Egresos</p>
            <p className='mt-1 text-[18px] font-semibold text-destructive'>
              {displayMoney(data?.total_expenses)}
            </p>
          </div>
          <div className='rounded-[12px] border border-border p-4'>
            <p className='text-[12px] text-[var(--ink-3)]'>Neto</p>
            <p className='mt-1 text-[18px] font-semibold'>{displayMoney(data?.net)}</p>
          </div>
        </div>
      </PreviewState>
    </div>
  )
}

function AccountBalancesPreview() {
  const { data, isLoading, error } = useQuery<AccountBalanceRow[]>({
    queryKey: ['reports', 'preview', 'account_balances'],
    queryFn: () => ReportsResource.previewAccountBalances(),
  })

  return (
    <PreviewState isLoading={isLoading} error={error} empty={!data || data.length === 0}>
      <div className='max-h-[55vh] overflow-y-auto rounded-[12px] border border-border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Cuenta</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Propósito</TableHead>
              <TableHead className='text-right'>Saldo actual</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.map((account) => (
              <TableRow key={account.id}>
                <TableCell className='font-medium'>
                  {account.name}
                  {account.is_primary && (
                    <span className='ml-1.5 text-[11px] text-[var(--green-deep)]'>· Principal</span>
                  )}
                </TableCell>
                <TableCell>{account.type?.name ?? '—'}</TableCell>
                <TableCell>{account.purpose?.name ?? '—'}</TableCell>
                <TableCell className='text-right tabular-nums'>
                  {displayMoney(account.current_balance)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </PreviewState>
  )
}

function AuditLogPreview() {
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useQuery<PaginatedResult<AuditLogRow>>({
    queryKey: ['reports', 'preview', 'audit_log', page],
    queryFn: () => ReportsResource.previewAuditLog(page),
  })

  const shortType = (type?: string | null) => (type ? type.split('\\').pop() : '—')

  return (
    <PreviewState isLoading={isLoading} error={error} empty={!data || data.data.length === 0}>
      <div className='space-y-3'>
        <div className='max-h-[50vh] overflow-y-auto rounded-[12px] border border-border'>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Evento</TableHead>
                <TableHead>Entidad</TableHead>
                <TableHead>Usuario</TableHead>
                <TableHead>Fecha</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data?.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className='font-medium'>{row.event ?? '—'}</TableCell>
                  <TableCell>{shortType(row.auditable_type)}</TableCell>
                  <TableCell className='max-w-[160px] truncate text-[12px] text-[var(--ink-3)]'>
                    {row.user_id ?? '—'}
                  </TableCell>
                  <TableCell>{row.created_at ? displayFormatDate(row.created_at) : '—'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        {data && data.last_page > 1 && (
          <div className='flex items-center justify-end gap-2 text-[12.5px]'>
            <Button
              variant='outline'
              size='sm'
              className='h-7 rounded-full px-3'
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
            >
              Anterior
            </Button>
            <span className='text-[var(--ink-3)]'>
              Página {data.current_page} de {data.last_page}
            </span>
            <Button
              variant='outline'
              size='sm'
              className='h-7 rounded-full px-3'
              disabled={page >= data.last_page}
              onClick={() => setPage((p) => p + 1)}
            >
              Siguiente
            </Button>
          </div>
        )}
      </div>
    </PreviewState>
  )
}
