import { Button } from '@/components/ui/button'
import { CHARGE_STATUS, chargeStatusTone } from '@/modules/charge/schema'
import { toChargeRows } from '@/modules/chargeBatch/components/chargeRows'
import { useBatchUnitCharges } from '@/modules/chargeBatch/hooks/useBatchUnitCharges'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { Skeleton } from 'boneyard-js/react'
import { formatMinorUnits } from '@/shared/utils/money'
import { useState } from 'react'
import { Link } from 'react-router-dom'

interface BatchUnitChargesTableProps {
  batchId: string
  /** Permite diferir el fetch hasta que el periodo se expande (cockpit). */
  enabled?: boolean
  /** Callback de cobro: si se pasa, muestra el botón "Cobrar" en cargos con faltante. */
  onCollect?: (_unit: { id: string; label: string }) => void
  /** `compact` reduce paddings/typografía para el drill dentro del card del periodo. */
  compact?: boolean
}

const HEADERS = ['Unidad', 'Esperado', 'Pagado', 'Faltante', 'Estado', '']

/**
 * Tabla de cargos por unidad de UN lote — extraída del tab "Por unidad" de
 * ChargeBatchDetailPage para reusarse en el drill del cockpit de políticas.
 * Hace su propio fetch paginado (filtro charge_batch_id) y resuelve
 * loading/empty/error. La paginación es server-side: 25 filas por página.
 */
export function BatchUnitChargesTable({
  batchId,
  enabled = true,
  onCollect,
  compact = false,
}: BatchUnitChargesTableProps) {
  // Página actual del listado paginado.
  // El reset a 1 cuando cambia batchId se gestiona externamente mediante la
  // prop key={batchId} en el padre, que provoca el remonte del componente
  // y reinicia todo el estado local (React best practice para reset en prop change).
  const [page, setPage] = useState(1)

  const { data: result, isLoading, isError } = useBatchUnitCharges(batchId, enabled, page)

  const charges = result?.charges ?? []
  const total = result?.total ?? 0
  const pageSize = result?.pageSize ?? 25
  const lastPage = result?.lastPage ?? 1
  const rows = toChargeRows(charges)
  const cellY = compact ? 'py-2' : 'py-[11px]'

  // Rango de filas mostradas (p. ej. "1–25 de 80")
  const rangeFrom = total === 0 ? 0 : (page - 1) * pageSize + 1
  const rangeTo = Math.min(page * pageSize, total)

  if (isLoading) {
    return (
      <Skeleton name='batch-unit-charges' loading>
        <div className='space-y-2 p-4'>
          {[0, 1, 2].map((i) => (
            <div key={i} className='h-8 w-full rounded-[8px] bg-muted/30' />
          ))}
        </div>
      </Skeleton>
    )
  }

  if (isError) {
    return (
      <p className='px-4 py-6 text-center text-[13px] text-[var(--r)]'>
        No se pudieron cargar los cargos de este periodo.
      </p>
    )
  }

  if (rows.length === 0) {
    return (
      <p className='px-4 py-6 text-center text-[13px] text-[var(--ink-3)]'>
        Este periodo aún no tiene cargos generados.
      </p>
    )
  }

  return (
    <div>
      {/* Desktop (md+): tabla */}
      <div className='hidden md:block'>
        <table className='w-full border-collapse text-[13px]'>
          <thead>
            <tr>
              {HEADERS.map((header, index) => (
                <th
                  key={header || 'accion'}
                  scope='col'
                  className={`border-b border-border bg-[var(--surface-2)] px-4 py-2.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--ink-3)] ${index >= 1 && index <= 3 ? 'text-right' : 'text-left'}`}
                >
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map(({ charge, expected, paid, outstanding }) => (
              <tr
                key={charge.id}
                className='border-b border-[var(--line-2)] last:border-b-0 hover:bg-[var(--surface-2)]'
              >
                <td className={`px-4 font-medium ${cellY}`}>
                  {charge.unit?.full_identifier ?? charge.unit?.number ?? '—'}
                </td>
                <td className={`px-4 text-right tabular-nums ${cellY}`}>{formatMinorUnits(expected)}</td>
                <td className={`px-4 text-right tabular-nums ${cellY}`}>{formatMinorUnits(paid)}</td>
                <td className={`px-4 text-right tabular-nums ${cellY}`}>{formatMinorUnits(outstanding)}</td>
                <td className={`px-4 ${cellY}`}>
                  <StatusPill tone={chargeStatusTone(charge.charge_status_id)}>
                    {charge.charge_status?.name ?? '—'}
                  </StatusPill>
                </td>
                <td className={`px-4 text-right ${cellY}`}>
                  {outstanding > 0 && onCollect ? (
                    <PermissionGuard permission='create.payment'>
                      <Button
                        size='sm'
                        className='h-7 rounded-full px-3 text-[12px]'
                        onClick={() =>
                          onCollect({
                            id: charge.unit_id,
                            label: charge.unit?.full_identifier ?? charge.unit?.number ?? '',
                          })
                        }
                      >
                        Cobrar
                      </Button>
                    </PermissionGuard>
                  ) : (
                    <Link
                      to={`/statement?unit=${charge.unit_id}`}
                      className='text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
                    >
                      Ver
                    </Link>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Mobile (< md): filas apiladas */}
      <ul className='md:hidden' role='list'>
        {rows.map(({ charge, expected, paid, outstanding }) => (
          <li key={charge.id} className='border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'>
            <div className='flex items-center justify-between gap-3'>
              <span className='font-semibold text-foreground'>
                {charge.unit?.full_identifier ?? charge.unit?.number ?? '—'}
              </span>
              <StatusPill tone={chargeStatusTone(charge.charge_status_id)}>
                {charge.charge_status?.name ?? '—'}
              </StatusPill>
            </div>
            <div className='mt-2 grid grid-cols-3 gap-2 text-[12.5px]'>
              <div>
                <div className='text-[var(--ink-3)]'>Esperado</div>
                <div className='font-medium tabular-nums'>{formatMinorUnits(expected)}</div>
              </div>
              <div>
                <div className='text-[var(--ink-3)]'>Pagado</div>
                <div className='font-medium tabular-nums'>{formatMinorUnits(paid)}</div>
              </div>
              <div>
                <div className='text-[var(--ink-3)]'>Faltante</div>
                <div
                  className={`font-medium tabular-nums ${outstanding > 0 && charge.charge_status_id === CHARGE_STATUS.OVERDUE ? 'text-[var(--r)]' : ''}`}
                >
                  {formatMinorUnits(outstanding)}
                </div>
              </div>
            </div>
            {outstanding > 0 && onCollect ? (
              <div className='mt-2.5'>
                <PermissionGuard permission='create.payment'>
                  <Button
                    size='sm'
                    className='h-7 rounded-full px-3 text-[12px]'
                    onClick={() =>
                      onCollect({
                        id: charge.unit_id,
                        label: charge.unit?.full_identifier ?? charge.unit?.number ?? '',
                      })
                    }
                  >
                    Cobrar
                  </Button>
                </PermissionGuard>
              </div>
            ) : (
              <div className='mt-2'>
                <Link
                  to={`/statement?unit=${charge.unit_id}`}
                  className='text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
                >
                  Ver estado →
                </Link>
              </div>
            )}
          </li>
        ))}
      </ul>

      {/* Paginador: solo se renderiza cuando hay más de una página */}
      {lastPage > 1 && (
        <div className={`flex items-center justify-between border-t border-[var(--line-2)] px-4 ${cellY}`}>
          <span className='text-[12px] text-[var(--ink-3)]'>
            Mostrando {rangeFrom}–{rangeTo} de {total}
          </span>
          <div className='flex items-center gap-2'>
            <Button
              size='sm'
              variant='outline'
              className='h-7 rounded-full px-3 text-[12px]'
              disabled={page <= 1}
              aria-label='Página anterior'
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Anterior
            </Button>
            <Button
              size='sm'
              variant='outline'
              className='h-7 rounded-full px-3 text-[12px]'
              disabled={page >= lastPage}
              aria-label='Página siguiente'
              onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
