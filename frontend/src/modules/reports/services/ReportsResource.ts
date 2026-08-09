import { apiClient } from '@/shared/api/apiClient'
import type { IMoney } from '@/shared/utils/money'

/** Reportes con vista previa read-only dentro de la app (§15.2). */
export const PREVIEWABLE_REPORTS = new Set([
  'delinquency',
  'income_statement',
  'account_balances',
  'audit_log',
])

export interface DelinquencyUnit {
  unit_id: string | null
  unit_number: string | null
  unit_full_identifier: string | null
  overdue_charges: number
  total_overdue_amount: IMoney
  oldest_due_date: string | null
}

export interface DelinquencyReport {
  units: DelinquencyUnit[]
  total_overdue_amount: IMoney
  unit_count: number
}

export interface IncomeStatementReport {
  period_start: string
  period_end: string
  total_income: IMoney
  total_expenses: IMoney
  net: IMoney
}

export interface AccountBalanceRow {
  id: string
  name: string
  current_balance: IMoney | number | string | null
  is_primary?: boolean
  type?: { name?: string } | null
  purpose?: { name?: string } | null
}

export interface AuditLogRow {
  id: string | number
  event?: string | null
  auditable_type?: string | null
  user_id?: string | null
  created_at?: string | null
}

export interface PaginatedResult<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

export interface GeneratedReport {
  id: string
  report_key?: string
  format?: string
  file_name?: string | null
  created_at?: string
  expires_at?: string | null
  status?: string | null
}

export const REPORT_CATALOG: Array<{ key: string; label: string; description: string }> = [
  {
    key: 'delinquency',
    label: 'Morosidad',
    description: 'Unidades con saldo vencido y antigüedad',
  },
  {
    key: 'income_statement',
    label: 'Ingresos vs egresos',
    description: 'Estado de resultados del periodo',
  },
  {
    key: 'account_balances',
    label: 'Saldos de cuentas',
    description: 'Saldo actual por cuenta bancaria y caja',
  },
  { key: 'cash_flow', label: 'Flujo de efectivo', description: 'Entradas y salidas por mes' },
  {
    key: 'expenses_by_category',
    label: 'Egresos por categoría',
    description: 'Distribución del gasto',
  },
  {
    key: 'global_statement',
    label: 'Estado de cuenta global',
    description: 'Resumen de todas las unidades',
  },
  {
    key: 'payment_history',
    label: 'Histórico de pagos',
    description: 'Pagos registrados con folio y método',
  },
  {
    key: 'credit_balances',
    label: 'Saldos a favor',
    description: 'Unidades con saldo a favor vigente',
  },
  {
    key: 'access_log_report',
    label: 'Bitácora de accesos',
    description: 'Entradas y salidas del condominio',
  },
  { key: 'staff_report', label: 'Personal', description: 'Plantilla de personal operativo' },
  {
    key: 'provider_history',
    label: 'Proveedores',
    description: 'Histórico de pagos a proveedores',
  },
  { key: 'audit_log', label: 'Auditoría', description: 'Bitácora de cambios del sistema' },
  { key: 'annual_fiscal', label: 'Anual fiscal', description: 'Resumen anual para contabilidad' },
]

export const ReportsResource = {
  /** Genera y descarga el reporte en el formato indicado */
  async generate(key: string, format: 'pdf' | 'excel' | 'csv'): Promise<Blob> {
    const { data } = await apiClient.post(
      `/reports/${key}/generate`,
      { format },
      {
        responseType: 'blob',
        timeout: 120000,
      }
    )
    return data
  },
  async listGenerated(): Promise<GeneratedReport[]> {
    const { data } = await apiClient.get<{ data: GeneratedReport[] }>('/reports/generated')
    return data.data
  },
  async download(id: string): Promise<Blob> {
    const { data } = await apiClient.get(`/reports/generated/${id}/download`, {
      responseType: 'blob',
    })
    return data
  },
  /** Publica el reporte como aviso en el tablero del condominio (§15.4). */
  async publishToBoard(key: string, title?: string): Promise<unknown> {
    const { data } = await apiClient.post(
      `/reports/${key}/publish-to-board`,
      title ? { title } : {}
    )
    return data
  },

  /** Vista previa: morosidad por unidad (§15.2). */
  async previewDelinquency(): Promise<DelinquencyReport> {
    const { data } = await apiClient.get<DelinquencyReport>('/reports/delinquency')
    return data
  },
  /** Vista previa: estado de resultados del periodo. */
  async previewIncomeStatement(
    periodStart: string,
    periodEnd: string
  ): Promise<IncomeStatementReport> {
    const { data } = await apiClient.get<IncomeStatementReport>('/reports/income-statement', {
      params: { period_start: periodStart, period_end: periodEnd },
    })
    return data
  },
  /** Vista previa: saldos por cuenta bancaria y caja. */
  async previewAccountBalances(): Promise<AccountBalanceRow[]> {
    const { data } = await apiClient.get<{ data: AccountBalanceRow[] }>('/reports/account-balances')
    return data.data
  },
  /** Vista previa: bitácora de auditoría (requiere read.audit). */
  async previewAuditLog(page = 1): Promise<PaginatedResult<AuditLogRow>> {
    const { data } = await apiClient.get<PaginatedResult<AuditLogRow>>('/reports/audit-log', {
      params: { page },
    })
    return data
  },
}

export function saveBlob(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  // Defer cleanup: revoking the object URL synchronously right after click()
  // makes Safari/Firefox drop the download (the file lands with no name or
  // extension). Give the browser a tick to start the download first.
  setTimeout(() => {
    link.remove()
    URL.revokeObjectURL(url)
  }, 0)
}
