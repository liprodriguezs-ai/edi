import type { IIssueRow, IValidateResponse } from '@/modules/import/schema'
import { IconAlertTriangle, IconCircleCheck, IconXboxX } from '@tabler/icons-react'
import { Button } from '@/components/ui/button'

interface ValidateStepProps {
  response: IValidateResponse
  onContinue: () => void
  onBack: () => void
}

/**
 * ValidateStep — Step 2 of the import wizard.
 *
 * Displays the dry-run preview counts and per-row issues.
 * Error rows are highlighted in red; warning rows in amber.
 * "Continuar" is disabled when response.blocked > 0 (there are errors).
 *
 * NO client-side validation logic — just renders what the API returned.
 */
export function ValidateStep({ response, onContinue, onBack }: ValidateStepProps) {
  const hasBlocked = response.blocked > 0
  const errorRows = response.rows.filter((r) => r.severity === 'error')
  const warningRows = response.rows.filter((r) => r.severity === 'warning')

  return (
    <div className='space-y-6'>
      <div>
        <h3 className='text-base font-semibold'>Paso 2 — Resultado de validación</h3>
        <p className='text-sm text-muted-foreground'>
          Revisión completa del archivo. Los errores bloquean la importación; las advertencias no.
        </p>
      </div>

      {/* Summary counts */}
      <div className='grid grid-cols-2 gap-3 sm:grid-cols-4'>
        <SummaryCard label='Total de filas' value={response.total} />
        <SummaryCard label='Pagos a registrar' value={response.payments_to_register} />
        <SummaryCard label='Unidades nuevas' value={response.units_to_create} />
        <SummaryCard label='Residentes nuevos' value={response.residents_to_create} />
      </div>

      {/* Truncation notice — only the first row_cap rows were previewed */}
      {response.truncated && (
        <div className='flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-400'>
          <IconAlertTriangle className='h-4 w-4 shrink-0' />
          <span>{response.preview_message}</span>
        </div>
      )}

      {/* Blocked indicator */}
      {hasBlocked && (
        <div className='flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400'>
          <IconXboxX className='h-4 w-4 shrink-0' />
          <span>
            <strong>{response.blocked}</strong> fila{response.blocked !== 1 ? 's' : ''} con error
            {response.blocked !== 1 ? 'es' : ''} que impiden la importación. Corrige el archivo y
            vuelve a subir.
          </span>
        </div>
      )}

      {!hasBlocked && (
        <div className='flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-400'>
          <IconCircleCheck className='h-4 w-4 shrink-0' />
          <span>
            {response.truncated
              ? 'Sin errores en las filas revisadas.'
              : 'Sin errores. El archivo está listo para importar.'}
          </span>
        </div>
      )}

      {/* Error rows */}
      {errorRows.length > 0 && (
        <IssueTable title='Errores (filas bloqueadas)' issues={errorRows} tone='error' />
      )}

      {/* Warning rows */}
      {warningRows.length > 0 && (
        <IssueTable
          title='Advertencias (filas incluidas con aviso)'
          issues={warningRows}
          tone='warning'
        />
      )}

      {/* Actions */}
      <div className='flex justify-end gap-3'>
        <Button variant='outline' onClick={onBack} data-testid='back-button'>
          Subir otro archivo
        </Button>
        <Button onClick={onContinue} disabled={hasBlocked} data-testid='continue-button'>
          Continuar
        </Button>
      </div>
    </div>
  )
}

// ─── Sub-components ────────────────────────────────────────────────────────────

interface SummaryCardProps {
  label: string
  value: number
}

function SummaryCard({ label, value }: SummaryCardProps) {
  return (
    <div className='rounded-lg border bg-card p-4 text-center'>
      <p className='text-2xl font-bold'>{value.toLocaleString('es-MX')}</p>
      <p className='mt-0.5 text-xs text-muted-foreground'>{label}</p>
    </div>
  )
}

type IssueTone = 'error' | 'warning'

interface IssueTableProps {
  title: string
  issues: IIssueRow[]
  tone: IssueTone
}

function IssueTable({ title, issues, tone }: IssueTableProps) {
  const rowClass =
    tone === 'error' ? 'bg-red-50 dark:bg-red-900/10' : 'bg-amber-50 dark:bg-amber-900/10'

  const headerClass =
    tone === 'error' ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400'

  const badgeClass =
    tone === 'error'
      ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
      : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'

  return (
    <div>
      <div className={`mb-2 flex items-center gap-2 text-sm font-medium ${headerClass}`}>
        <IconAlertTriangle className='h-4 w-4' />
        {title}
        <span className={`rounded-full px-2 py-0.5 text-xs ${badgeClass}`}>{issues.length}</span>
      </div>
      <div className='overflow-x-auto rounded-md border'>
        <table className='min-w-full text-sm'>
          <thead>
            <tr className='border-b bg-muted/50 text-left'>
              <th className='px-3 py-2 font-medium text-muted-foreground'>Fila</th>
              <th className='px-3 py-2 font-medium text-muted-foreground'>Columna</th>
              <th className='px-3 py-2 font-medium text-muted-foreground'>Mensaje</th>
            </tr>
          </thead>
          <tbody>
            {issues.map((issue, i) => (
              <tr key={i} className={`border-b last:border-0 ${rowClass}`}>
                <td className='px-3 py-2 tabular-nums'>
                  {issue.row === 0
                    ? 'Archivo'
                    : issue.count && issue.count > 1
                      ? `${issue.count.toLocaleString('es-MX')} filas`
                      : issue.row}
                </td>
                <td className='px-3 py-2 font-mono text-xs'>{issue.column}</td>
                <td className='px-3 py-2'>{issue.message}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
