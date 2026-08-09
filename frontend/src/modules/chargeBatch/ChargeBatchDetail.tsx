import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { CHARGE_BATCH_STATUS } from '@/modules/chargeBatch/schema'
import { ChargeBatchResource } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { BatchUnitChargesTable } from '@/modules/chargeBatch/components/BatchUnitChargesTable'
import { policyAmountLabel } from '@/modules/chargeBatch/components/PolicySummaryPanel'
import { useChargeBatchDetail } from '@/modules/chargeBatch/hooks/useChargeBatchDetail'
import { useChargeBatchStats } from '@/modules/chargeBatch/hooks/useChargeBatchStats'
import { RegisterPaymentSheet } from '@/modules/payment/components/RegisterPaymentSheet'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { ChargeLifecycleTimeline } from '@/shared/components/molecules/ChargeLifecycleTimeline'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { toast } from '@/shared/hooks/useToast'
import {
  computeConcreteLifecycleMilestones,
  resolveCurrentStage,
  resolveTimingSettings,
} from '@/shared/utils/chargeLifecycle'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

const KPI = 'rounded-[14px] border border-border bg-card p-4 shadow-sm'
const KPI_LABEL =
  'flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'
const KPI_VALUE = 'mt-1 text-[22px] font-semibold tracking-[-0.01em] tabular-nums'

interface ChargeBatchDetailProps {
  batchId: string
}

/**
 * Detalle de cobro (§8.4.4, pantalla "cobro" del mock v3): KPIs del lote,
 * distribución por estado y tabla por unidad con acción de cobro.
 */
export function ChargeBatchDetail({ batchId }: ChargeBatchDetailProps) {
  const queryClient = useQueryClient()
  const [payUnit, setPayUnit] = useState<{ id: string; label: string } | null>(null)
  const [confirmActivate, setConfirmActivate] = useState(false)

  const { batch, isLoading, isError, isSuccess, notFound, refetch, collectionSettings } =
    useChargeBatchDetail(batchId)
  const { stats } = useChargeBatchStats(batchId, isSuccess)

  const activateMutation = useMutation({
    mutationFn: () => ChargeBatchResource.activate(batchId),
    onSuccess: () => {
      toast.success('Cobro activado — se generaron los cargos')
      setConfirmActivate(false)
      void queryClient.invalidateQueries({ queryKey: ['charges'] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo activar el cobro'
      ),
  })

  if (isLoading) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando cobro…</p>
  }

  if (isError || !batch) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Cobro no encontrado' : undefined}
        backTo='/charge-batch'
        backLabel='Volver a cobros'
        onRetry={notFound ? undefined : refetch}
      />
    )
  }

  const isDraft = batch.charge_batch_status_id === CHARGE_BATCH_STATUS.DRAFT
  const expectedMinor = moneyToMinorUnits(stats.expected)
  const paidMinor = moneyToMinorUnits(stats.paid)
  const progressPct = expectedMinor > 0 ? Math.round((paidMinor / expectedMinor) * 100) : 0

  // Ciclo de vida concreto del lote (funciones puras, no hooks → seguras tras el early return).
  const timing = resolveTimingSettings(collectionSettings)
  const graceDays = batch.policy?.grace_days ?? 0
  const lifecycleMilestones = computeConcreteLifecycleMilestones({
    issuedAt: batch.issued_at,
    dueDate: batch.due_date,
    dueSoonDays: timing.dueSoonDays,
    graceDays,
  })
  const lifecycleStage = resolveCurrentStage(lifecycleMilestones)

  // El monto representativo se resuelve por modo (fijo/rango/prorrateo/voluntario);
  // leer policy.fixed_amount crudo mostraría "$0.00" en políticas no-fijas.
  const policyAmountText = batch.policy ? policyAmountLabel(batch.policy) : null

  return (
    <div className='space-y-5'>
      {/* encabezado */}
      <div className='flex flex-wrap items-start justify-between gap-4'>
        <div>
          <Link
            to='/charge-batch'
            className='mb-1 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground'
          >
            <ArrowLeft className='size-3.5' />
            Cobros programados
          </Link>
          <h1 className='text-[28px] font-semibold leading-[1.12] tracking-[-0.02em]'>
            {batch.title}
          </h1>
          <p className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
            {batch.policy?.concept?.code ?? ''} · vence{' '}
            {displayFormatDate(batch.due_date, 'mediumDate')} ·{' '}
            <StatusPill tone={isDraft ? 'y' : 'g'}>{batch.status?.name ?? '—'}</StatusPill>
          </p>
        </div>
        {isDraft && (
          <PermissionGuard permission='update.charge_batch'>
            <Button className='rounded-full' onClick={() => setConfirmActivate(true)}>
              Activar y generar cargos
            </Button>
          </PermissionGuard>
        )}
      </div>

      {/* KPIs */}
      <div className='grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4'>
        <div className={KPI}>
          <div className={KPI_LABEL}>
            <span className='size-2.5 rounded-[4px] bg-[var(--green)]' aria-hidden />
            Total esperado
          </div>
          <div className={KPI_VALUE}>{displayMoney(stats.expected)}</div>
          <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
            {stats.total} cargos generados
          </div>
        </div>
        <div className={KPI}>
          <div className={KPI_LABEL}>
            <span className='size-2.5 rounded-[4px] bg-[#3F9A5E]' aria-hidden />
            Total pagado
          </div>
          <div className={KPI_VALUE}>{displayMoney(stats.paid)}</div>
          <div className='mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--line-2)]'>
            <i
              className='block h-full rounded-full bg-[#3F9A5E]'
              style={{ width: `${Math.min(100, progressPct)}%` }}
            />
          </div>
          <div className='mt-1 text-[12px] text-[var(--ink-3)]'>{progressPct}% del esperado</div>
        </div>
        <div className={KPI}>
          <div className={KPI_LABEL}>
            <span className='size-2.5 rounded-[4px] bg-[#C0533C]' aria-hidden />
            Total faltante
          </div>
          <div className={KPI_VALUE}>{displayMoney(stats.outstanding)}</div>
          <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>
            {stats.partial + stats.unpaid} cargos sin completar
          </div>
        </div>
        <div className={KPI}>
          <div className={KPI_LABEL}>
            <span className='size-2.5 rounded-[4px] bg-[#B7923B]' aria-hidden />
            Vencidos
          </div>
          <div className={KPI_VALUE}>{stats.overdue}</div>
          <div className='mt-0.5 text-[12px] text-[var(--ink-3)]'>cargos vencidos</div>
        </div>
      </div>

      {/* leyenda */}
      <div className='flex flex-wrap gap-4 text-[13px]'>
        <span className='flex items-center gap-1.5'>
          <i className='size-2 rounded-full bg-[#3F9A5E]' />
          <b className='font-semibold'>{stats.completed}</b> completos
        </span>
        <span className='flex items-center gap-1.5'>
          <i className='size-2 rounded-full bg-[#B7923B]' />
          <b className='font-semibold'>{stats.partial}</b> parciales
        </span>
        <span className='flex items-center gap-1.5'>
          <i className='size-2 rounded-full bg-[#C0533C]' />
          <b className='font-semibold'>{stats.unpaid}</b> sin pago
        </span>
      </div>

      {/* Ciclo de vida del cobro */}
      <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
        <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Ciclo de vida del cobro
        </p>
        <ChargeLifecycleTimeline
          mode='concrete'
          milestones={lifecycleMilestones}
          activeStage={lifecycleStage}
        />
      </div>

      <Tabs defaultValue='unidad'>
        <TabsList>
          <TabsTrigger value='unidad'>Por unidad</TabsTrigger>
          <TabsTrigger value='estado'>Por estado</TabsTrigger>
        </TabsList>

        <TabsContent value='unidad' className='mt-4'>
          <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
            <BatchUnitChargesTable
              key={batchId}
              batchId={batchId}
              onCollect={(unit) => setPayUnit(unit)}
            />
          </div>
        </TabsContent>

        <TabsContent value='estado' className='mt-4'>
          <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
            {[
              {
                label: 'Completos',
                count: stats.completed,
                amount: stats.paid,
                color: '#3F9A5E',
                tone: 'g' as const,
              },
              {
                label: 'Parciales',
                count: stats.partial,
                amount: stats.partial_paid,
                color: '#B7923B',
                tone: 'y' as const,
              },
              {
                label: 'Sin pago',
                count: stats.unpaid,
                amount: stats.unpaid_outstanding,
                color: '#C0533C',
                tone: 'r' as const,
              },
            ].map((row) => {
              const pct = stats.total > 0 ? Math.round((row.count / stats.total) * 100) : 0
              return (
                <div key={row.label} className='mb-4 last:mb-0'>
                  <div className='mb-1.5 flex items-baseline justify-between gap-3 text-[13px]'>
                    <StatusPill tone={row.tone}>{row.label}</StatusPill>
                    <span className='text-[var(--ink-3)]'>
                      <b className='font-semibold text-foreground'>{row.count}</b> ·{' '}
                      {displayMoney(row.amount)}
                    </span>
                  </div>
                  <div className='h-1.5 overflow-hidden rounded-full bg-[var(--line-2)]'>
                    <i
                      className='block h-full rounded-full'
                      style={{ width: `${pct}%`, backgroundColor: row.color }}
                    />
                  </div>
                </div>
              )
            })}
          </div>
        </TabsContent>
      </Tabs>

      <RegisterPaymentSheet
        isOpen={Boolean(payUnit)}
        onClose={() => setPayUnit(null)}
        presetUnitId={payUnit?.id}
        presetUnitLabel={payUnit?.label}
      />

      <ConfirmDialog
        isOpen={confirmActivate}
        title='Activar cobro'
        description={`Se generarán los cargos para las unidades del alcance.${policyAmountText ? ` Monto base: ${policyAmountText}.` : ''}`}
        confirmLabel='Activar'
        isLoading={activateMutation.isPending}
        onConfirm={() => activateMutation.mutate()}
        onCancel={() => setConfirmActivate(false)}
      />
    </div>
  )
}
