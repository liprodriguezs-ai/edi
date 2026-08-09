import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { CHARGE_BATCH_STATUS, type IChargeBatch } from '@/modules/chargeBatch/schema'
import { ChargeBatchResource } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { useChargeBatchStore } from '@/modules/chargeBatch/store/chargeBatchStore'
import { StatusPill, type PillTone } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Download, Loader2 } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

const MONTHS = [
  '',
  'Ene',
  'Feb',
  'Mar',
  'Abr',
  'May',
  'Jun',
  'Jul',
  'Ago',
  'Sep',
  'Oct',
  'Nov',
  'Dic',
]

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

/** Color del avance (§8.4.1): ≥80 verde, 40–79 ámbar, <40 rojo */
function progressColor(pct: number): string {
  if (pct >= 80) return 'var(--g, #3F9A5E)'
  if (pct >= 40) return '#B7923B'
  return '#C0533C'
}

export const ChargeBatchListPage = () => {
  const navigate = useNavigate()

  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<IChargeBatch>({
      store: useChargeBatchStore,
      resource: ChargeBatchResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'issued_at', direction: 'desc' }],
        includes: [{ relation: 'policy' }, { relation: 'policy.concept' }, { relation: 'status' }],
      },
    })
  const { entities, pagination, isLoading } = useChargeBatchStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Cobros programados'
        description='Lotes de cobro generados por concepto y periodo'
        actions={
          <PermissionGuard permission='report.charge_batch'>
            <Button
              variant='outline'
              onClick={() => onReport('ChargeBatch', 'xlsx', 'cobros')}
              disabled={isExporting}
              className='flex items-center gap-2'
            >
              {isExporting ? (
                <Loader2 className='h-4 w-4 animate-spin' />
              ) : (
                <Download className='h-4 w-4' />
              )}
              Descargar
            </Button>
          </PermissionGuard>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder='Buscar por título…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Aún no hay cobros'
            description='Crea el primero para generar cargos a las unidades.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IChargeBatch>
              rows={entities}
              onRowClick={(batch) => navigate(`/charge-batch/${batch.id}`)}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Título</TableHead>
                    <TableHead scope='col'>Concepto</TableHead>
                    <TableHead scope='col'>Periodo</TableHead>
                    <TableHead scope='col'>Vence</TableHead>
                    <TableHead scope='col' className='w-[180px]'>
                      Avance
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(batch) => {
                const pct = Math.round(Number(batch.progress_pct ?? 0))
                return (
                  <TableRow
                    key={batch.id}
                    className='cursor-pointer'
                    onClick={() => navigate(`/charge-batch/${batch.id}`)}
                  >
                    <TableCell className='font-medium'>{batch.title}</TableCell>
                    <TableCell className='font-mono text-[12px] text-[var(--ink-2)]'>
                      {batch.policy?.concept?.code ?? '—'}
                    </TableCell>
                    <TableCell>
                      {batch.month
                        ? `${MONTHS[batch.month]} ${batch.year}`
                        : batch.year || 'Eventual'}
                    </TableCell>
                    <TableCell>{displayFormatDate(batch.due_date, 'mediumDate')}</TableCell>
                    <TableCell>
                      <div className='flex items-center gap-2'>
                        <div className='h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--line-2)]'>
                          <i
                            className='block h-full rounded-full'
                            style={{
                              width: `${Math.min(100, pct)}%`,
                              backgroundColor: progressColor(pct),
                            }}
                          />
                        </div>
                        <span className='w-9 text-right text-[12px] font-semibold tabular-nums'>
                          {pct}%
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <StatusPill tone={batchTone(batch)}>{batch.status?.name ?? '—'}</StatusPill>
                    </TableCell>
                  </TableRow>
                )
              }}
              renderCardTitle={(batch) => batch.title}
              renderCardFields={(batch) => {
                const pct = Math.round(Number(batch.progress_pct ?? 0))
                return [
                  {
                    label: 'Concepto',
                    value: (
                      <span className='font-mono text-[12px]'>
                        {batch.policy?.concept?.code ?? '—'}
                      </span>
                    ),
                  },
                  {
                    label: 'Periodo',
                    value: batch.month
                      ? `${MONTHS[batch.month]} ${batch.year}`
                      : batch.year || 'Eventual',
                  },
                  { label: 'Vence', value: displayFormatDate(batch.due_date, 'mediumDate') },
                  {
                    label: 'Avance',
                    value: (
                      <div className='flex items-center gap-2 min-w-[100px]'>
                        <div className='h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--line-2)]'>
                          <i
                            className='block h-full rounded-full'
                            style={{
                              width: `${Math.min(100, pct)}%`,
                              backgroundColor: progressColor(pct),
                            }}
                          />
                        </div>
                        <span className='w-9 text-right text-[12px] font-semibold tabular-nums'>
                          {pct}%
                        </span>
                      </div>
                    ),
                  },
                  {
                    label: 'Estado',
                    value: (
                      <StatusPill tone={batchTone(batch)}>{batch.status?.name ?? '—'}</StatusPill>
                    ),
                  },
                ]
              }}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>
    </div>
  )
}
