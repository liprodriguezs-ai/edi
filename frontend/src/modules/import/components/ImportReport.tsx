import type { IBankBalanceDelta, IImportReport, IIssueRow } from '@/modules/import/schema'
import {
  IconAlertTriangle,
  IconBuildingBank,
  IconCircleCheck,
  IconCurrencyDollar,
  IconHome,
  IconUser,
} from '@tabler/icons-react'

interface ImportReportProps {
  report: IImportReport
}

/**
 * ImportReport — displays the final execution report after the import finishes.
 *
 * Shows:
 *  - Summary cards (units, residents, payments registered / skipped)
 *  - Bank balance deltas (money formatted in MXN)
 *  - Per-row issues (warnings from blocked rows that were skipped)
 */
export function ImportReport({ report }: ImportReportProps) {
  const warningRows = report.rows.filter((r) => r.severity === 'warning')
  const errorRows = report.rows.filter((r) => r.severity === 'error')

  return (
    <div className="space-y-6" data-testid="import-report">
      <div>
        <h3 className="text-base font-semibold">Reporte de importación</h3>
        <p className="text-sm text-muted-foreground">
          Resumen de todas las operaciones realizadas durante la importación.
        </p>
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <ReportCard
          icon={<IconHome className="h-5 w-5 text-blue-500" />}
          label="Unidades creadas"
          value={report.units_created}
          data-testid="units-created"
        />
        <ReportCard
          icon={<IconUser className="h-5 w-5 text-violet-500" />}
          label="Residentes creados"
          value={report.residents_created}
        />
        <ReportCard
          icon={<IconCurrencyDollar className="h-5 w-5 text-emerald-500" />}
          label="Pagos registrados"
          value={report.payments_registered}
          data-testid="payments-registered"
        />
        <ReportCard
          icon={<IconCircleCheck className="h-5 w-5 text-amber-500" />}
          label="Pagos omitidos"
          value={report.payments_skipped}
          data-testid="payments-skipped"
        />
      </div>

      {/* Bank balance deltas */}
      {report.bank_balance_delta.length > 0 && (
        <BankDeltaSection deltas={report.bank_balance_delta} />
      )}

      {/* Error rows (blocked/skipped) */}
      {errorRows.length > 0 && (
        <IssueSection
          title="Filas omitidas (con errores)"
          issues={errorRows}
          tone="error"
        />
      )}

      {/* Warning rows */}
      {warningRows.length > 0 && (
        <IssueSection
          title="Advertencias"
          issues={warningRows}
          tone="warning"
        />
      )}
    </div>
  )
}

// ─── Sub-components ─────────────────────────────────────────────────────────

interface ReportCardProps {
  icon: React.ReactNode
  label: string
  value: number
  'data-testid'?: string
}

function ReportCard({ icon, label, value, 'data-testid': testId }: ReportCardProps) {
  return (
    <div className="rounded-lg border bg-card p-4" data-testid={testId}>
      <div className="mb-2 flex items-center gap-2 text-muted-foreground">
        {icon}
        <span className="text-xs font-medium">{label}</span>
      </div>
      <p className="text-2xl font-bold">{value.toLocaleString('es-MX')}</p>
    </div>
  )
}

interface BankDeltaSectionProps {
  deltas: IBankBalanceDelta[]
}

function BankDeltaSection({ deltas }: BankDeltaSectionProps) {
  return (
    <div>
      <div className="mb-2 flex items-center gap-2 text-sm font-medium text-muted-foreground">
        <IconBuildingBank className="h-4 w-4" />
        Movimientos en cuentas
      </div>
      <div className="overflow-x-auto rounded-md border">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/50 text-left">
              <th className="px-3 py-2 font-medium text-muted-foreground">Cuenta</th>
              <th className="px-3 py-2 text-right font-medium text-muted-foreground">Incremento</th>
            </tr>
          </thead>
          <tbody>
            {deltas.map((d, i) => (
              <tr key={i} className="border-b last:border-0">
                <td className="px-3 py-2">{d.bank_account}</td>
                <td className="px-3 py-2 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                  {formatMoney(d.delta)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

type IssueTone = 'error' | 'warning'

interface IssueSectionProps {
  title: string
  issues: IIssueRow[]
  tone: IssueTone
}

function IssueSection({ title, issues, tone }: IssueSectionProps) {
  const headerClass =
    tone === 'error' ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400'

  const badgeClass =
    tone === 'error'
      ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
      : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'

  const rowClass =
    tone === 'error' ? 'bg-red-50 dark:bg-red-900/10' : 'bg-amber-50 dark:bg-amber-900/10'

  return (
    <div>
      <div className={`mb-2 flex items-center gap-2 text-sm font-medium ${headerClass}`}>
        <IconAlertTriangle className="h-4 w-4" />
        {title}
        <span className={`rounded-full px-2 py-0.5 text-xs ${badgeClass}`}>{issues.length}</span>
      </div>
      <div className="overflow-x-auto rounded-md border">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/50 text-left">
              <th className="px-3 py-2 font-medium text-muted-foreground">Fila</th>
              <th className="px-3 py-2 font-medium text-muted-foreground">Columna</th>
              <th className="px-3 py-2 font-medium text-muted-foreground">Mensaje</th>
            </tr>
          </thead>
          <tbody>
            {issues.map((issue, i) => (
              <tr key={i} className={`border-b last:border-0 ${rowClass}`}>
                <td className="px-3 py-2 tabular-nums">{issue.row}</td>
                <td className="px-3 py-2 font-mono text-xs">{issue.column}</td>
                <td className="px-3 py-2">{issue.message}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/**
 * Format minor units (centavos) as MXN currency string.
 * E.g. 150000 → "$1,500.00"
 */
function formatMoney(minorUnits: number): string {
  return new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
  }).format(minorUnits / 100)
}
