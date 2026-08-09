import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import type { IBillingLedgerEntry } from '@/modules/tenant/billing/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'

const METHOD_LABELS: Record<string, string> = {
  card: 'Tarjeta',
  transfer: 'Transferencia',
  cash: 'Efectivo',
}

interface LedgerSectionProps {
  ledger: IBillingLedgerEntry[]
  ledgerTotal: number
}

/**
 * Historial de pagos registrados del clúster (Stripe + manuales) con el total
 * cobrado. Es de sólo lectura: refleja lo asentado en el ledger del backend.
 */
export function LedgerSection({ ledger, ledgerTotal }: LedgerSectionProps) {
  const currency = ledger[0]?.currency ?? 'MXN'

  return (
    <section aria-labelledby="billing-ledger-heading" className="rounded-xl border border-border bg-card p-5 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h3 id="billing-ledger-heading" className="text-base font-semibold text-foreground">
            Historial de pagos
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Todos los pagos asentados para este condominio.
          </p>
        </div>
        <div className="text-right">
          <p className="text-xs text-muted-foreground">Total cobrado</p>
          <p className="text-lg font-semibold tabular-nums text-foreground">
            {formatPrice(ledgerTotal, currency)}
          </p>
        </div>
      </div>

      {ledger.length === 0 ? (
        <p className="mt-4 rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
          Aún no hay pagos registrados para este condominio.
        </p>
      ) : (
        <div className="mt-4 overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead scope="col">Fecha</TableHead>
                <TableHead scope="col">Método</TableHead>
                <TableHead scope="col" className="text-right">Monto</TableHead>
                <TableHead scope="col" className="hidden sm:table-cell">Cubre hasta</TableHead>
                <TableHead scope="col" className="hidden md:table-cell">Notas</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {ledger.map((entry, index) => (
                <TableRow key={`${entry.paid_at ?? 'sin-fecha'}-${index}`}>
                  <TableCell className="whitespace-nowrap">
                    {entry.paid_at ? displayFormatDate(entry.paid_at, 'mediumDate') : '—'}
                  </TableCell>
                  <TableCell>{METHOD_LABELS[entry.method] ?? entry.method}</TableCell>
                  <TableCell className="text-right tabular-nums font-medium">
                    {formatPrice(entry.amount, entry.currency)}
                  </TableCell>
                  <TableCell className="hidden sm:table-cell whitespace-nowrap text-muted-foreground">
                    {entry.period_end ? displayFormatDate(entry.period_end, 'mediumDate') : '—'}
                  </TableCell>
                  <TableCell className="hidden md:table-cell text-muted-foreground">
                    {entry.notes || '—'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </section>
  )
}
