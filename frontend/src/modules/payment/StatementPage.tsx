import { Button } from '@/components/ui/button'
import { RegisterPaymentSheet } from '@/modules/payment/components/RegisterPaymentSheet'
import { SubmitPaymentSheet } from '@/modules/payment/components/SubmitPaymentSheet'
import { UnitChargesTable } from '@/modules/payment/components/UnitChargesTable'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { ConfigurationResource } from '@/modules/configuration/services/ConfigurationResource'
import { UnitCombobox } from '@/modules/unit/components/UnitCombobox'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { toast } from '@/shared/hooks/useToast'
import {
  displayMoney,
  formatMinorUnits,
  moneyToMinorUnits,
  type IMoney,
} from '@/shared/utils/money'
import { useQueries, useQuery } from '@tanstack/react-query'
import { Copy, Plus } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

/** Totales por unidad (adeudo/saldo/pagado). La lista de cargos vive paginada aparte. */
interface StatementData {
  credit_balance: IMoney
  totals?: {
    expected: IMoney
    paid: IMoney
    outstanding: IMoney
  }
}

// ─── sub-components ──────────────────────────────────────────────────────────

interface BankDataInfo {
  name?: string | null
  bank?: string | null
  account_number?: string | null
  clabe?: string | null
}

interface BankDataPanelProps {
  data: BankDataInfo
  onCopyClabe: (_clabe: string) => void
}

function BankDataPanel({ data, onCopyClabe }: BankDataPanelProps) {
  return (
    <div className='rounded-[14px] border border-border bg-[var(--surface-2)] p-4'>
      <div className='mb-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Datos para transferencia
      </div>
      <div className='flex flex-wrap items-center gap-x-8 gap-y-1.5 text-[13px]'>
        {data.name && (
          <span>
            <span className='text-[var(--ink-3)]'>Cuenta:</span>{' '}
            <b className='font-semibold'>{data.name}</b>
          </span>
        )}
        {data.bank && (
          <span>
            <span className='text-[var(--ink-3)]'>Banco:</span>{' '}
            <b className='font-semibold'>{data.bank}</b>
          </span>
        )}
        {data.account_number && (
          <span>
            <span className='text-[var(--ink-3)]'>Cuenta:</span>{' '}
            <b className='font-mono'>{data.account_number}</b>
          </span>
        )}
        {data.clabe && (
          <span className='inline-flex items-center gap-1.5'>
            <span className='text-[var(--ink-3)]'>CLABE:</span>{' '}
            <b className='font-mono'>{data.clabe}</b>
            <button
              type='button'
              onClick={() => onCopyClabe(data.clabe!)}
              className='text-[var(--ink-3)] hover:text-foreground'
              aria-label='Copiar CLABE'
            >
              <Copy className='size-3.5' />
            </button>
          </span>
        )}
      </div>
    </div>
  )
}

interface GrandTotalsProps {
  unitsCount: number
  outstanding: number
  credit: number
  paid: number
}

/** Resumen consolidado de TODAS las unidades del usuario (total general del estado de cuenta). */
function GrandTotalCards({ unitsCount, outstanding, credit, paid }: GrandTotalsProps) {
  const outstandingColor = outstanding > 0 ? 'text-[#C0533C]' : 'text-foreground'
  return (
    <div className='rounded-[16px] border border-border bg-[var(--surface-2)] p-4'>
      <div className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Total general · {unitsCount} unidades
      </div>
      <div className='grid grid-cols-1 gap-3.5 sm:grid-cols-3'>
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Adeudo total
          </div>
          <div className={`mt-1 text-[24px] font-semibold tabular-nums ${outstandingColor}`}>
            {formatMinorUnits(outstanding)}
          </div>
        </div>
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Saldo a favor
          </div>
          <div className='mt-1 text-[24px] font-semibold tabular-nums text-[#3F6E8E]'>
            {formatMinorUnits(credit)}
          </div>
        </div>
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Pagado histórico
          </div>
          <div className='mt-1 text-[24px] font-semibold tabular-nums'>
            {formatMinorUnits(paid)}
          </div>
        </div>
      </div>
    </div>
  )
}

interface StatementContentProps {
  statement: StatementData
  unitId: string
}

function StatementContent({ statement, unitId }: StatementContentProps) {
  const totals = statement.totals
  const outstandingColor =
    moneyToMinorUnits(totals?.outstanding) > 0 ? 'text-[#C0533C]' : 'text-foreground'
  return (
    <>
      {/* resumen (backend-computed, cacheado por unidad) */}
      <div className='grid grid-cols-1 gap-3.5 sm:grid-cols-3'>
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Adeudo total
          </div>
          <div className={`mt-1 text-[24px] font-semibold tabular-nums ${outstandingColor}`}>
            {displayMoney(totals?.outstanding)}
          </div>
        </div>
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Saldo a favor
          </div>
          <div className='mt-1 text-[24px] font-semibold tabular-nums text-[#3F6E8E]'>
            {displayMoney(statement.credit_balance)}
          </div>
        </div>
        <div className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
          <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Pagado histórico
          </div>
          <div className='mt-1 text-[24px] font-semibold tabular-nums'>
            {displayMoney(totals?.paid)}
          </div>
        </div>
      </div>

      {/* tabla paginada de cargos (reusa la lista de cargos, sin filtros) */}
      <UnitChargesTable unitId={unitId} />
    </>
  )
}

// ─── main component ──────────────────────────────────────────────────────────

/**
 * Estado de cuenta por unidad (§8.6.2, pantalla "unidad" del mock v3):
 * tarjetas de adeudo/saldo/pagado + tabla paginada de cargos.
 */
export const StatementPage = () => {
  const [searchParams] = useSearchParams()
  const [unitId, setUnitId] = useState(searchParams.get('unit') ?? '')
  const [unitLabel, setUnitLabel] = useState('')
  const [payOpen, setPayOpen] = useState(false)
  const [submitOpen, setSubmitOpen] = useState(false)

  // Unidades del propio usuario (residente/inquilino/cualquier rol con unidad).
  // Si las tiene, ve SU estado de cuenta directo; si no (admin/tesorero), busca cualquier unidad.
  const { data: ownUnits = [] } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })
  const browseMode = ownUnits.length === 0

  // Estado de cuenta de TODAS las unidades propias (un residente puede tener varias):
  // se muestra una sección por unidad + un total general consolidado.
  const ownStatementQueries = useQueries({
    queries: (browseMode ? [] : ownUnits).map((u) => ({
      queryKey: ['statement', u.id],
      queryFn: () => PaymentResource.statement(u.id),
      enabled: Boolean(u.id),
    })),
  })
  const ownLoading = !browseMode && ownStatementQueries.some((q) => q.isLoading)
  const grandTotals = useMemo(
    () =>
      ownStatementQueries.reduce(
        (acc, q) => {
          const s = q.data as StatementData | undefined
          if (s) {
            acc.paid += moneyToMinorUnits(s.totals?.paid)
            acc.outstanding += moneyToMinorUnits(s.totals?.outstanding)
            acc.credit += moneyToMinorUnits(s.credit_balance)
          }
          return acc
        },
        { paid: 0, outstanding: 0, credit: 0 }
      ),
    [ownStatementQueries]
  )

  const openPayFor = (id: string, label: string) => {
    setUnitId(id)
    setUnitLabel(label)
    setPayOpen(true)
  }
  const openSubmitFor = (id: string, label: string) => {
    setUnitId(id)
    setUnitLabel(label)
    setSubmitOpen(true)
  }

  const {
    entity: statement,
    isLoading,
    isError: statementError,
    isSuccess: statementSuccess,
    notFound: statementNotFound,
    refetch: refetchStatement,
  } = useEntityDetail<StatementData>({
    queryKey: ['statement', unitId],
    queryFn: () => PaymentResource.statement(unitId),
    enabled: Boolean(unitId),
  })

  // Deep-link `?unit=<uuid>` (ej. desde Morosidad): hay unitId pero no etiqueta →
  // buscamos la unidad para poblar el combobox con su identificador legible.
  const { data: deepLinkedUnit } = useQuery({
    queryKey: ['unit', 'lookup', unitId],
    queryFn: () => UnitResource.show(unitId),
    enabled: Boolean(unitId) && unitLabel === '',
  })
  // Etiqueta a mostrar en el combobox: la elegida por el usuario, o la del deep-link.
  const comboboxLabel = unitLabel || deepLinkedUnit?.full_identifier || deepLinkedUnit?.number || ''

  // Solo trae los datos bancarios cuando ya hay un estado de cuenta cargado (evita el fetch
  // al entrar sin unidad seleccionada). En modo propio se activa al cargar alguna unidad.
  const statementReady = browseMode
    ? statementSuccess
    : ownStatementQueries.some((q) => q.isSuccess)

  const { data: bankData } = useQuery({
    queryKey: ['config', 'bank-data'],
    queryFn: () => ConfigurationResource.getBankData(),
    enabled: statementReady,
  })

  const copyClabe = (clabe: string) => {
    void navigator.clipboard?.writeText(clabe)
    toast.success('CLABE copiada')
  }

  return (
    <div className='space-y-5'>
      <div className='flex flex-wrap items-end justify-between gap-4'>
        <div>
          <div className='mb-[7px] text-[10.5px] font-semibold uppercase tracking-[0.15em] text-[var(--ink-4)]'>
            Cobranza
          </div>
          <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
            Estado de <b className='font-semibold text-[var(--green)]'>cuenta</b>
          </h1>
          <div className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
            {browseMode
              ? 'Consulta los cargos y saldos de cualquier unidad'
              : ownUnits.length > 1
                ? `Tus ${ownUnits.length} unidades`
                : 'Tu estado de cuenta'}
          </div>
        </div>
        {browseMode && unitId && (
          <div className='flex gap-2'>
            <PermissionGuard permission='create.payment_submission'>
              <Button
                variant='outline'
                className='rounded-full'
                onClick={() => setSubmitOpen(true)}
              >
                <Plus className='size-4' />
                Reportar pago
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.payment'>
              <Button className='rounded-full' onClick={() => setPayOpen(true)}>
                <Plus className='size-4' />
                Registrar pago
              </Button>
            </PermissionGuard>
          </div>
        )}
      </div>

      {bankData?.visible && bankData.data && (
        <BankDataPanel data={bankData.data} onCopyClabe={copyClabe} />
      )}

      {browseMode ? (
        <>
          <div className='max-w-md'>
            <UnitCombobox
              // Remonta cuando llega la etiqueta del deep-link para re-sembrar el input.
              key={comboboxLabel}
              value={unitId}
              initialLabel={comboboxLabel || undefined}
              onSelect={(unit) => {
                setUnitId(unit.id)
                setUnitLabel(unit.full_identifier ?? unit.number ?? '')
              }}
              placeholder='Buscar unidad para ver su estado de cuenta…'
            />
          </div>

          {!unitId ? (
            <p className='rounded-[14px] border border-border bg-card px-6 py-12 text-center text-sm text-[var(--ink-3)]'>
              Selecciona una unidad para consultar su estado de cuenta.
            </p>
          ) : isLoading ? (
            <p className='py-12 text-center text-sm text-[var(--ink-3)]'>
              Cargando estado de cuenta…
            </p>
          ) : statementError || !statement ? (
            <EntityNotFound
              variant={statementNotFound ? 'notFound' : 'error'}
              title={statementNotFound ? 'Estado de cuenta no disponible' : undefined}
              description={
                statementNotFound ? 'No encontramos el estado de cuenta de esta unidad.' : undefined
              }
              onRetry={statementNotFound ? undefined : refetchStatement}
            />
          ) : (
            <StatementContent statement={statement} unitId={unitId} />
          )}
        </>
      ) : ownLoading ? (
        <p className='py-12 text-center text-sm text-[var(--ink-3)]'>Cargando estado de cuenta…</p>
      ) : (
        <div className='space-y-6'>
          {ownUnits.length > 1 && (
            <GrandTotalCards
              unitsCount={ownUnits.length}
              outstanding={grandTotals.outstanding}
              credit={grandTotals.credit}
              paid={grandTotals.paid}
            />
          )}
          {ownUnits.map((u, index) => {
            const unitStatement = ownStatementQueries[index]?.data as StatementData | undefined
            if (!unitStatement) return null
            const label = u.full_identifier ?? u.number ?? 'Unidad'
            return (
              <section key={u.id} className='space-y-3'>
                <div className='flex flex-wrap items-center justify-between gap-2'>
                  <h2 className='text-[17px] font-semibold tracking-[-0.01em]'>{label}</h2>
                  <div className='flex gap-2'>
                    <PermissionGuard permission='create.payment_submission'>
                      <Button
                        variant='outline'
                        className='rounded-full'
                        onClick={() => openSubmitFor(u.id, label)}
                      >
                        <Plus className='size-4' />
                        Reportar pago
                      </Button>
                    </PermissionGuard>
                    <PermissionGuard permission='create.payment'>
                      <Button className='rounded-full' onClick={() => openPayFor(u.id, label)}>
                        <Plus className='size-4' />
                        Registrar pago
                      </Button>
                    </PermissionGuard>
                  </div>
                </div>
                <StatementContent statement={unitStatement} unitId={u.id} />
              </section>
            )
          })}
        </div>
      )}

      <RegisterPaymentSheet
        isOpen={payOpen}
        onClose={() => setPayOpen(false)}
        presetUnitId={unitId}
        presetUnitLabel={unitLabel}
      />

      <SubmitPaymentSheet
        isOpen={submitOpen}
        onClose={() => setSubmitOpen(false)}
        unitId={unitId}
        unitLabel={unitLabel}
      />
    </div>
  )
}
