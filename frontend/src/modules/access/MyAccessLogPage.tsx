import { Button } from '@/components/ui/button'
import {
  TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import { useMyAccessLog } from '@/modules/access/hooks/useMyAccessLog'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import type { IAccessLog } from '@/modules/access/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

/** Estado visual de un registro de bitácora del residente. */
function MyLogStatus({ log }: { log: IAccessLog }) {
  if (log.denied_at) return <StatusPill tone="r">Denegado</StatusPill>
  if (log.exited_at) return <StatusPill tone="b">Salió</StatusPill>
  return <StatusPill tone="g">Dentro</StatusPill>
}

/**
 * Historial de accesos de la(s) unidad(es) del residente (§14.6): bitácora
 * read-only de entradas/salidas de sus visitas. GET /access/my-log (paginado).
 */
export const MyAccessLogPage = () => {
  const [page, setPage] = useState(1)
  const { logs, lastPage, isLoading, isError } = useMyAccessLog(page)

  return (
    <div className="space-y-5">
      <div>
        <Link to="/my-unit" className="mb-1 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Mi unidad
        </Link>
        <PageHeader title="Historial de accesos" description="Entradas y salidas registradas para tu unidad" />
      </div>

      {isLoading ? (
        <TableLoadingState />
      ) : isError ? (
        <TableEmptyState title="Error al cargar el historial" description="No se pudo obtener la bitácora. Intenta de nuevo más tarde." />
      ) : logs.length === 0 ? (
        <TableEmptyState title="Sin accesos registrados" description="Aquí verás el historial de entradas de tus visitas." />
      ) : (
        <>
          <ResponsiveDataTable<IAccessLog>
            rows={logs}
            header={
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Visitante</TableHead>
                  <TableHead scope="col">Unidad</TableHead>
                  <TableHead scope="col">Tipo</TableHead>
                  <TableHead scope="col">Vehículo</TableHead>
                  <TableHead scope="col">Entró</TableHead>
                  <TableHead scope="col">Salió</TableHead>
                  <TableHead scope="col">Estado</TableHead>
                </TableRow>
              </TableHeader>
            }
            renderRow={(log) => (
              <TableRow key={log.id}>
                <TableCell className="font-medium">{log.visitor_name ?? log.visitor?.name ?? '—'}</TableCell>
                <TableCell>{log.unit?.full_identifier ?? log.unit?.number ?? '—'}</TableCell>
                <TableCell>{log.access_entry_type?.name ?? '—'}</TableCell>
                <TableCell>
                  {log.plate
                    ? <span className="rounded border border-border bg-[var(--surface-2)] px-1.5 font-mono text-[12px]">{log.plate}</span>
                    : '—'}
                </TableCell>
                <TableCell>{log.entered_at ? displayFormatDate(log.entered_at) : '—'}</TableCell>
                <TableCell>{log.exited_at ? displayFormatDate(log.exited_at) : '—'}</TableCell>
                <TableCell><MyLogStatus log={log} /></TableCell>
              </TableRow>
            )}
            renderCardTitle={(log) => log.visitor_name ?? log.visitor?.name ?? '—'}
            renderCardFields={(log) => [
              { label: 'Unidad', value: log.unit?.full_identifier ?? log.unit?.number ?? '—' },
              { label: 'Tipo', value: log.access_entry_type?.name ?? '—' },
              { label: 'Vehículo', value: log.plate ?? '—' },
              { label: 'Entró', value: log.entered_at ? displayFormatDate(log.entered_at) : '—' },
              { label: 'Salió', value: log.exited_at ? displayFormatDate(log.exited_at) : '—' },
              { label: 'Estado', value: <MyLogStatus log={log} /> },
            ]}
          />

          {lastPage > 1 && (
            <div className="flex items-center justify-end gap-2">
              <Button variant="outline" size="sm" className="rounded-full" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <ChevronLeft className="size-4" />
              </Button>
              <span className="text-[12.5px] text-[var(--ink-3)]">Página {page} de {lastPage}</span>
              <Button variant="outline" size="sm" className="rounded-full" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
                <ChevronRight className="size-4" />
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  )
}
