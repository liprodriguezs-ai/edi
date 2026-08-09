import { CHARGE_BATCH_STATUS, type IChargeBatch } from '@/modules/chargeBatch/schema'
import { ChargeBatchResource } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { BatchUnitChargesTable } from '@/modules/chargeBatch/components/BatchUnitChargesTable'
import { periodLabel } from '@/modules/chargeBatch/periodLabel'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  ResponsiveDataTable,
  type ResponsiveField,
} from '@/shared/components/molecules/ResponsiveDataTable'
import { Pagination } from '@/shared/components/Pagination'
import { StatusPill, type PillTone } from '@/shared/components/atoms/StatusPill'
import { paginationFromHeuristic, paginationFromMeta } from '@/shared/hooks/baseListParams'
import type { IPagination } from '@/shared/interfaces/list.types'
import { Skeleton } from 'boneyard-js/react'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight } from 'lucide-react'
import { Fragment, useCallback, useState } from 'react'
import { Link } from 'react-router-dom'

/** Tamaño de página por defecto del listado de cobros generados por la regla. */
const PAGE_SIZE = 10
const PAGE_SIZE_OPTIONS = [10, 20, 50]
/** Nº de columnas de la tabla de lotes — usado por el colSpan de la fila expandida. */
const COLUMN_COUNT = 5

type CollectHandler = (_unit: { id: string; label: string }) => void

function batchTone(batch: IChargeBatch): PillTone {
  switch (batch.charge_batch_status_id) {
    case CHARGE_BATCH_STATUS.ACTIVE:
      return 'g'
    case CHARGE_BATCH_STATUS.DRAFT:
      return 'y'
    default:
      return 'r'
  }
}

/** Color del avance: ≥80 verde, 40–79 ámbar, <40 rojo (tokens de estado financiero). */
function progressColor(pct: number): string {
  if (pct >= 80) return 'var(--g)'
  if (pct >= 40) return 'var(--y)'
  return 'var(--r)'
}

/** Barra de avance + porcentaje — compartida entre la fila desktop y la card mobile. */
function ProgressBar({ pct }: { pct: number }) {
  return (
    <div className='flex w-[140px] items-center gap-2'>
      <div className='h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--line-2)]'>
        <i
          className='block h-full rounded-full'
          style={{ width: `${Math.min(100, pct)}%`, backgroundColor: progressColor(pct) }}
        />
      </div>
      <span className='w-9 text-right text-[12px] font-semibold tabular-nums'>{pct}%</span>
    </div>
  )
}

/** Botón chevron accesible que controla la expansión de la fila/card de un lote. */
function ExpandToggle({
  isOpen,
  controls,
  label,
  onToggle,
}: {
  isOpen: boolean
  controls: string
  label: string
  onToggle: () => void
}) {
  return (
    <button
      type='button'
      aria-expanded={isOpen}
      aria-controls={controls}
      aria-label={label}
      onClick={(e) => {
        e.stopPropagation()
        onToggle()
      }}
      className='inline-flex size-7 flex-none items-center justify-center rounded-md text-[var(--ink-3)] transition-colors hover:bg-[var(--surface-2)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
    >
      <ChevronRight
        aria-hidden
        className={`size-4 transition-transform duration-200 ${isOpen ? 'rotate-90' : ''}`}
      />
    </button>
  )
}

/** Drill de cargos por unidad — reusa BatchUnitChargesTable. Fetch LAZY (solo al expandir). */
function BatchDrill({ batch, onCollect }: { batch: IChargeBatch; onCollect?: CollectHandler }) {
  return (
    <div className='border-t border-border'>
      <BatchUnitChargesTable batchId={batch.id} enabled onCollect={onCollect} compact />
      <div className='px-4 py-2.5 text-right'>
        <Link
          to={`/charge-batch/${batch.id}`}
          className='text-[12.5px] font-medium text-[var(--ink-3)] transition-colors hover:text-foreground hover:underline'
        >
          Abrir cobro completo →
        </Link>
      </div>
    </div>
  )
}

interface CockpitBatchListProps {
  policyId: string
  /**
   * Periodicidad PERSISTIDA de la regla — decide cómo se etiqueta el período de
   * cada lote (ver `periodLabel`). Se toma de la entidad cargada, no del valor del
   * form, para que cambiar el desplegable sin guardar no reetiquete lo ya generado.
   */
  periodicityId?: string | null
  /** Callback de cobro propagado al drill de cargos por unidad. */
  onCollect?: CollectHandler
}

/**
 * Trazabilidad de la regla: cobros generados (un lote por periodo) en una TABLA
 * PAGINADA con filas expandibles. Reusa ResponsiveDataTable (tabla desktop + cards
 * mobile) y Pagination; cada fila se expande LAZY a la tabla de cargos por unidad
 * (BatchUnitChargesTable). El fetch va paginado por charge_policy_id + is_initial=false.
 */
export function CockpitBatchList({ policyId, periodicityId, onCollect }: CockpitBatchListProps) {
  const [expandedId, setExpandedId] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(PAGE_SIZE)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['charges', 'batches', 'by-policy', policyId, page, pageSize],
    queryFn: () =>
      ChargeBatchResource.search({
        page,
        limit: pageSize,
        filters: [
          { field: 'charge_policy_id', operator: '=', value: policyId },
          { field: 'is_initial', operator: '=', value: false },
        ],
        includes: [{ relation: 'status' }],
        sort: [{ field: 'issued_at', direction: 'desc' }],
      }),
    enabled: Boolean(policyId),
    // Mantiene la página previa visible mientras carga la siguiente (sin parpadeo).
    placeholderData: (previous) => previous,
  })

  const toggle = useCallback((id: string) => setExpandedId((prev) => (prev === id ? null : id)), [])

  const handlePageChanged = useCallback(
    (nextPage: number, nextSize?: number) => {
      setExpandedId(null)
      if (nextSize && nextSize !== pageSize) {
        setPageSize(nextSize)
        setPage(1)
        return
      }
      setPage(nextPage)
    },
    [pageSize]
  )

  if (isLoading) {
    return (
      <Skeleton name='cockpit-batches' loading>
        <ul className='space-y-3' role='list'>
          {[0, 1, 2].map((i) => (
            <li
              key={i}
              className='flex items-center gap-3 rounded-[14px] border border-border bg-card px-4 py-3 shadow-sm'
            >
              <div className='size-4 flex-none rounded-[4px] bg-muted/30' />
              <div className='min-w-0 flex-1'>
                <div className='flex items-center gap-2'>
                  <div className='h-4 w-24 rounded-[6px] bg-muted/30' />
                  <div className='h-4 w-16 rounded-full bg-muted/30' />
                </div>
                <div className='mt-1.5 h-3 w-28 rounded-[6px] bg-muted/30' />
              </div>
              <div className='flex w-[120px] flex-none items-center gap-2'>
                <div className='h-1.5 flex-1 rounded-full bg-muted/30' />
                <div className='h-3 w-9 rounded-[6px] bg-muted/30' />
              </div>
            </li>
          ))}
        </ul>
      </Skeleton>
    )
  }

  if (isError) {
    return (
      <p className='rounded-[14px] border border-border bg-card px-4 py-10 text-center text-[13px] text-[var(--r)]'>
        No se pudieron cargar los cobros de esta programación.
      </p>
    )
  }

  const batches = data?.data ?? []

  if (batches.length === 0) {
    return (
      <div className='rounded-[14px] border border-dashed border-border bg-card px-4 py-12 text-center'>
        <p className='text-[14px] font-medium text-foreground'>Aún sin cobros generados</p>
        <p className='mt-1 text-[12.5px] leading-relaxed text-[var(--ink-3)]'>
          Cuando la programación genere su primer cobro aparecerá aquí, con su avance y sus cargos
          por unidad.
        </p>
      </div>
    )
  }

  const pagination: IPagination = data?.meta
    ? paginationFromMeta(data.meta, { page, limit: pageSize }, batches.length, data.total)
    : paginationFromHeuristic({ page, limit: pageSize }, batches.length, data?.total)

  return (
    <div className='space-y-4'>
      <ResponsiveDataTable<IChargeBatch>
        rows={batches}
        header={
          <TableHeader>
            <TableRow>
              <TableHead scope='col' className='w-10'>
                <span className='sr-only'>Detalle</span>
              </TableHead>
              <TableHead scope='col'>Período</TableHead>
              <TableHead scope='col'>Estado</TableHead>
              <TableHead scope='col'>Vence</TableHead>
              <TableHead scope='col'>Avance</TableHead>
            </TableRow>
          </TableHeader>
        }
        renderRow={(batch) => {
          const isOpen = expandedId === batch.id
          const pct = Math.round(Number(batch.progress_pct ?? 0))
          const regionId = `cockpit-drill-${batch.id}`
          return (
            <Fragment key={batch.id}>
              <TableRow
                className='cursor-pointer'
                data-state={isOpen ? 'open' : undefined}
                onClick={() => toggle(batch.id)}
              >
                <TableCell className='w-10'>
                  <ExpandToggle
                    isOpen={isOpen}
                    controls={regionId}
                    label={`${isOpen ? 'Ocultar' : 'Ver'} cargos de ${periodLabel(batch, periodicityId)}`}
                    onToggle={() => toggle(batch.id)}
                  />
                </TableCell>
                <TableCell className='font-medium'>{periodLabel(batch, periodicityId)}</TableCell>
                <TableCell>
                  <StatusPill tone={batchTone(batch)}>{batch.status?.name ?? '—'}</StatusPill>
                </TableCell>
                <TableCell>{displayFormatDate(batch.due_date, 'mediumDate')}</TableCell>
                <TableCell>
                  <ProgressBar pct={pct} />
                </TableCell>
              </TableRow>
              {isOpen && (
                <TableRow className='hover:bg-transparent'>
                  <TableCell colSpan={COLUMN_COUNT} className='p-0'>
                    <div id={regionId}>
                      <BatchDrill batch={batch} onCollect={onCollect} />
                    </div>
                  </TableCell>
                </TableRow>
              )}
            </Fragment>
          )
        }}
        renderCardTitle={(batch) => periodLabel(batch, periodicityId)}
        renderActions={(batch) => {
          const isOpen = expandedId === batch.id
          return (
            <ExpandToggle
              isOpen={isOpen}
              controls={`cockpit-drill-m-${batch.id}`}
              label={`${isOpen ? 'Ocultar' : 'Ver'} cargos de ${periodLabel(batch, periodicityId)}`}
              onToggle={() => toggle(batch.id)}
            />
          )
        }}
        renderCardFields={(batch) => {
          const isOpen = expandedId === batch.id
          const pct = Math.round(Number(batch.progress_pct ?? 0))
          const fields: ResponsiveField[] = [
            {
              label: 'Estado',
              value: <StatusPill tone={batchTone(batch)}>{batch.status?.name ?? '—'}</StatusPill>,
            },
            { label: 'Vence', value: displayFormatDate(batch.due_date, 'mediumDate') },
            { label: 'Avance', value: <ProgressBar pct={pct} /> },
          ]
          if (isOpen) {
            fields.push({
              label: '',
              value: (
                <div id={`cockpit-drill-m-${batch.id}`} className='w-full text-left'>
                  <div className='-mx-4'>
                    <BatchDrill batch={batch} onCollect={onCollect} />
                  </div>
                </div>
              ),
            })
          }
          return fields
        }}
      />

      <Pagination
        pagination={pagination}
        onPageChanged={handlePageChanged}
        pageSizeOptions={PAGE_SIZE_OPTIONS}
      />
    </div>
  )
}
