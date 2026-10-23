import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import type { IBillingLedgerEntry } from '@/modules/tenant/billing/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { Panel } from '@/shared/components/molecules/Panel'

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
 * Historial de pagos registrados de la organización (Stripe + manuales) con el total
 * cobrado. Es de sólo lectura: refleja lo asentado en el ledger del backend.
 */
export function LedgerSection({ ledger, ledgerTotal }: LedgerSectionProps) {
  const currency = ledger[0]?.currency ?? 'MXN'

  return (
    <Panel
      title="Historial de pagos"
      description="Todos los pagos asentados para esta organización."
      actions={
        <div className="text-right">
          <p className="text-xs text-muted-foreground">Total cobrado</p>
          <p className="text-lg font-semibold font-mono tabular-nums text-foreground">
            {formatPrice(ledgerTotal, currency)}
          </p>
        </div>
      }
    >
      {ledger.length === 0 ? (
        <InlineEmpty>Aún no hay pagos registrados para esta organización.</InlineEmpty>
      ) : (
        <div className="overflow-x-auto border">
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
                  <TableCell className="text-right font-mono tabular-nums font-medium">
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
    </Panel>
  )
}
