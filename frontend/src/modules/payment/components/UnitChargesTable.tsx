import { chargeStatusLabel, chargeStatusTone, formatChargePeriod } from '@/modules/charge/schema'
import { ChargeResource } from '@/modules/charge/services/ChargeResource'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { paginationFromMeta } from '@/shared/hooks/baseListParams'
import type { ICharge } from '@/modules/charge/schema'
import type { IPagination } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { formatMinorUnits, moneyToMinorUnits } from '@/shared/utils/money'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'

const TABLE_HEADERS = ['Concepto', 'Periodo', 'Emitido', 'Esperado', 'Pagado', 'Saldo', 'Estado']
const RIGHT_COLS = new Set([3, 4, 5])
const DEFAULT_PAGE_SIZE = 20

interface ChargeRow {
  charge: ICharge
  expected: number
  paid: number
  outstanding: number
}

interface UnitChargesTableProps {
  unitId: string
}

/**
 * Tabla PAGINADA de cargos de una unidad para el Estado de Cuenta.
 *
 * Reusa la capa de datos de la lista de cargos (`ChargeResource.search` → `POST /charge/search`)
 * en vez de traer todo el historial de una: una unidad con años de cargos ya no descarga ni pinta
 * cientos de filas. Cada instancia lleva su PROPIO estado de página (no el store singleton de la
 * lista admin) porque un residente con varias unidades monta varias de estas tablas a la vez.
 */
export function UnitChargesTable({ unitId }: UnitChargesTableProps) {
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(DEFAULT_PAGE_SIZE)

  const { data, isLoading } = useQuery({
    queryKey: ['charge', 'by-unit', unitId, page, perPage],
    queryFn: () =>
      ChargeResource.search({
        page,
        limit: perPage,
        filters: [
          { field: 'unit_id', operator: '=', value: unitId },
          { field: 'is_cancelled', operator: '=', value: false },
        ],
        includes: [{ relation: 'concept' }, { relation: 'charge_status' }, { relation: 'batch' }],
        sort: [{ field: 'due_date', direction: 'asc' }],
      }),
    enabled: Boolean(unitId),
    // Mantiene la página anterior visible mientras carga la nueva (sin parpadeo a vacío).
    placeholderData: keepPreviousData,
  })

  const rows = useMemo<ChargeRow[]>(
    () =>
      (data?.data ?? []).map((charge) => {
        const expected = moneyToMinorUnits(charge.expected_amount)
        const discount = moneyToMinorUnits(charge.discount_amount ?? 0)
        const paid = moneyToMinorUnits(charge.paid_amount)
        return { charge, expected, paid, outstanding: Math.max(0, expected - discount - paid) }
      }),
    [data]
  )

  const pagination: IPagination | null = data?.meta
    ? paginationFromMeta(data.meta, { page, limit: perPage }, data.data.length, data.total)
    : null

  const onPageChanged = (nextPage: number, nextSize?: number) => {
    if (nextSize && nextSize !== perPage) {
      setPerPage(nextSize)
      setPage(1)
      return
    }
    setPage(nextPage)
  }

  if (isLoading) {
    return (
      <div className='rounded-[14px] border border-border bg-card px-4 py-8 text-center text-[13px] text-[var(--ink-3)] shadow-sm'>
        Cargando cargos…
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          La unidad no tiene cargos registrados.
        </p>
      </div>
    )
  }

  return (
    <div>
      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        {/* Desktop (md+): tabla completa */}
        <div className='hidden md:block'>
          <table className='w-full border-collapse text-[13px]'>
            <thead>
              <tr>
                {TABLE_HEADERS.map((header, index) => (
                  <th
                    key={header}
                    scope='col'
                    className={`border-b border-border bg-[var(--surface-2)] px-4 py-2.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--ink-3)] ${RIGHT_COLS.has(index) ? 'text-right' : 'text-left'}`}
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
                  <td className='px-4 py-[11px] font-medium text-foreground'>
                    {charge.concept?.name ?? '—'}
                  </td>
                  <td className='px-4 py-[11px] text-[var(--ink-2)]'>
                    {formatChargePeriod(charge)}
                  </td>
                  <td className='px-4 py-[11px] text-[var(--ink-2)]'>
                    {displayFormatDate(charge.issued_at, 'mediumDate') || '—'}
                  </td>
                  <td className='px-4 py-[11px] text-right tabular-nums'>
                    {formatMinorUnits(expected)}
                  </td>
                  <td className='px-4 py-[11px] text-right tabular-nums'>
                    {formatMinorUnits(paid)}
                  </td>
                  <td className='px-4 py-[11px] text-right tabular-nums'>
                    {formatMinorUnits(outstanding)}
                  </td>
                  <td className='px-4 py-[11px]'>
                    <StatusPill tone={chargeStatusTone(charge.charge_status_id)}>
                      {chargeStatusLabel(charge)}
                    </StatusPill>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {/* Mobile (< md): filas apiladas */}
        <ul className='md:hidden' role='list'>
          {rows.map(({ charge, expected, paid, outstanding }) => (
            <li
              key={charge.id}
              className='border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
            >
              <div className='flex items-start justify-between gap-3'>
                <span className='font-semibold text-foreground'>{charge.concept?.name ?? '—'}</span>
                <StatusPill tone={chargeStatusTone(charge.charge_status_id)}>
                  {chargeStatusLabel(charge)}
                </StatusPill>
              </div>
              <div className='mt-1 text-[12.5px] text-[var(--ink-3)]'>
                {formatChargePeriod(charge)}
                {charge.issued_at && (
                  <span> · Emitido {displayFormatDate(charge.issued_at, 'mediumDate')}</span>
                )}
              </div>
              <div className='mt-2 grid grid-cols-3 gap-2 text-[12.5px]'>
                <div>
                  <div className='text-[var(--ink-3)]'>Esperado</div>
                  <div className='tabular-nums font-medium'>{formatMinorUnits(expected)}</div>
                </div>
                <div>
                  <div className='text-[var(--ink-3)]'>Pagado</div>
                  <div className='tabular-nums font-medium'>{formatMinorUnits(paid)}</div>
                </div>
                <div>
                  <div className='text-[var(--ink-3)]'>Saldo</div>
                  <div
                    className={`tabular-nums font-medium ${outstanding > 0 ? 'text-[#C0533C]' : ''}`}
                  >
                    {formatMinorUnits(outstanding)}
                  </div>
                </div>
              </div>
            </li>
          ))}
        </ul>
      </div>
      {pagination && <Pagination pagination={pagination} onPageChanged={onPageChanged} />}
    </div>
  )
}
