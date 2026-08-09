import { Button } from '@/components/ui/button'
import { AddSurchargeDialog } from '@/modules/reservation/components/AddSurchargeDialog'
import { RegisterReservationPaymentSheet } from '@/modules/reservation/components/RegisterReservationPaymentSheet'
import { ReservationActionDialogs } from '@/modules/reservation/components/ReservationActionDialogs'
import { useReservationActions } from '@/modules/reservation/hooks/useReservationActions'
import {
  RESERVATION_ACTIVE_STATUSES,
  RESERVATION_PAYABLE_STATUSES,
  RESERVATION_STATUS,
  RESERVATION_SURCHARGEABLE_STATUSES,
  isTerminalReservationStatus,
  reservationStatusTone,
  type IAmenityReservation,
  type IReservationSurcharge,
} from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { PaymentActionButtons } from '@/modules/payment/components/PaymentActionButtons'
import { PaymentActionDialogs } from '@/modules/payment/components/PaymentActionDialogs'
import { usePaymentActions } from '@/modules/payment/hooks/usePaymentActions'
import {
  EARMARK_TYPE,
  PAYMENT_STATUS,
  paymentStatusTone,
  type IPayment,
} from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate, formatTimeHHmm } from '@/shared/utils/dateHelper'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

const DETAIL_INCLUDE = [
  'amenity',
  'amenity.bank_account',
  'unit',
  'slot',
  'reservation_status',
  'surcharges',
  'surcharges.charge',
  'surcharges.charge_concept',
].join(',')

// ─── sub-components ──────────────────────────────────────────────────────────

interface DetailFieldProps {
  label: string
  children: React.ReactNode
}

function DetailField({ label, children }: DetailFieldProps) {
  return (
    <div>
      <dt className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        {label}
      </dt>
      <dd className='mt-1 text-[14px] text-foreground'>{children}</dd>
    </div>
  )
}

interface SurchargesCardProps {
  surcharges: IReservationSurcharge[]
  canManage: boolean
  onAdd: () => void
}

function SurchargesCard({ surcharges, canManage, onAdd }: SurchargesCardProps) {
  return (
    <section className='overflow-hidden rounded-[16px] border border-border bg-card shadow-sm'>
      <div className='flex items-center justify-between gap-3 border-b border-border bg-[var(--surface-2)] px-4 py-3'>
        <div className='min-w-0'>
          <h2 className='text-[13px] font-semibold tracking-[-0.01em]'>Cargos adicionales</h2>
          <p className='mt-0.5 text-[11.5px] text-[var(--ink-3)]'>
            Multas o daños. Se cobran en el estado de cuenta de la unidad, aparte del saldo de la
            reservación.
          </p>
        </div>
        {canManage && (
          <Button size='sm' variant='outline' className='shrink-0' onClick={onAdd}>
            Agregar cargo adicional
          </Button>
        )}
      </div>
      {surcharges.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          Sin cargos adicionales aplicados.
        </p>
      ) : (
        <ul role='list'>
          {surcharges.map((surcharge) => (
            <li
              key={surcharge.id}
              className='flex items-center justify-between gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
            >
              <div className='min-w-0'>
                <div className='truncate font-medium text-foreground'>
                  {surcharge.charge_concept?.name ?? 'Cargo adicional'}
                </div>
                {surcharge.description && (
                  <div className='text-[12.5px] text-[var(--ink-3)]'>{surcharge.description}</div>
                )}
                {surcharge.created_at && (
                  <div className='text-[11.5px] text-[var(--ink-4)]'>
                    {displayFormatDate(surcharge.created_at, 'mediumDate')}
                  </div>
                )}
              </div>
              <div className='shrink-0 font-semibold tabular-nums'>
                {displayMoney(surcharge.amount)}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

interface PaymentsCardProps {
  payments: IPayment[]
  isLoading: boolean
  canManage: boolean
  paymentActions: ReturnType<typeof usePaymentActions>
}

function PaymentsCard({ payments, isLoading, canManage, paymentActions }: PaymentsCardProps) {
  return (
    <section className='overflow-hidden rounded-[16px] border border-border bg-card shadow-sm'>
      <div className='border-b border-border bg-[var(--surface-2)] px-4 py-3'>
        <h2 className='text-[13px] font-semibold tracking-[-0.01em]'>Pagos de la reservación</h2>
      </div>
      {isLoading ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>Cargando pagos…</p>
      ) : payments.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          Sin pagos registrados para esta reservación.
        </p>
      ) : (
        <ul role='list'>
          {payments.map((payment) => (
            <li
              key={payment.id}
              className='flex flex-wrap items-center justify-between gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
            >
              <div className='min-w-0'>
                <div className='flex items-center gap-2 font-mono text-[12.5px] text-[var(--ink-2)]'>
                  {payment.receipt_number ?? 'Pendiente'}
                  <StatusPill tone={paymentStatusTone(payment.payment_status_id)}>
                    {payment.payment_status?.name ?? '—'}
                  </StatusPill>
                </div>
                <div className='text-[12px] text-[var(--ink-3)]'>
                  {displayFormatDate(payment.paid_at, 'mediumDate')} ·{' '}
                  {payment.payment_method?.name ?? '—'}
                </div>
              </div>
              <div className='flex items-center gap-3'>
                <span className='font-semibold tabular-nums'>
                  {displayMoney(payment.total_amount)}
                </span>
                {canManage && (
                  <PaymentActionButtons
                    payment={payment}
                    show={{
                      download: Boolean(
                        payment.media?.find((m) => m.collection_name === 'receipt')
                      ),
                      validate: payment.payment_status_id === PAYMENT_STATUS.PENDING_VALIDATION,
                      reject: payment.payment_status_id === PAYMENT_STATUS.PENDING_VALIDATION,
                      cancel: payment.payment_status_id !== PAYMENT_STATUS.CANCELLED,
                    }}
                    downloadingId={paymentActions.downloadingId}
                    validatePending={paymentActions.validatePending}
                    rejectPending={paymentActions.rejectPending}
                    cancelPending={paymentActions.cancelPending}
                    onDownload={() => paymentActions.downloadReceipt(payment)}
                    onReject={() => paymentActions.requestReject(payment)}
                    onValidate={() => paymentActions.requestValidate(payment)}
                    onCancel={() => paymentActions.requestCancel(payment)}
                  />
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

interface ReservationHeaderProps {
  reservation: IAmenityReservation
  canManage: boolean
  outstandingMinor: number
  onAction: (_action: 'approve' | 'reject' | 'cancel' | 'complete' | 'no_show') => void
  onRegisterPayment: () => void
}

function ReservationHeader({
  reservation,
  canManage,
  outstandingMinor,
  onAction,
  onRegisterPayment,
}: ReservationHeaderProps) {
  const status = reservation.reservation_status_id
  const canApproveOrReject = status === RESERVATION_STATUS.PENDING_APPROVAL
  const canCancel = RESERVATION_ACTIVE_STATUSES.includes(status)
  const canCompleteOrNoShow = status === RESERVATION_STATUS.CONFIRMED
  const isPayableStatus = RESERVATION_PAYABLE_STATUSES.includes(status)
  // BUG FIX (E2E QA): a cancelled/rejected/completed/no-show reservation can
  // still carry a non-zero amount_outstanding (frozen at cancellation time,
  // see ReservationService::cancel()) — gate by status too, not just the
  // balance, so a dead reservation never shows a "Registrar pago" button.
  // A pending-validation earmark payment already exists — the backend would
  // 422 a second submission, so hide the action and show "Pago en revisión".
  const canRegisterPayment =
    isPayableStatus && outstandingMinor > 0 && !reservation.has_pending_payment
  const showPendingPaymentPill =
    isPayableStatus && outstandingMinor > 0 && Boolean(reservation.has_pending_payment)

  return (
    <div className='flex flex-wrap items-start justify-between gap-4'>
      <div>
        <h1 className='flex items-center gap-3 text-[26px] font-semibold tracking-[-0.02em]'>
          {reservation.amenity?.name ?? 'Reservación'}
          <StatusPill tone={reservationStatusTone(status)}>
            {reservation.reservation_status?.name ?? '—'}
          </StatusPill>
        </h1>
        <p className='mt-1 text-[13.5px] text-[var(--ink-3)]'>
          {reservation.unit?.full_identifier ?? reservation.unit?.number ?? '—'} ·{' '}
          {displayFormatDate(reservation.reservation_date, 'mediumDate')}
        </p>
      </div>

      {canManage && (
        <div className='flex flex-wrap items-center gap-2'>
          {showPendingPaymentPill && <StatusPill tone='y'>Pago en revisión</StatusPill>}
          {canRegisterPayment && (
            <Button className='rounded-full' onClick={onRegisterPayment}>
              Registrar pago
            </Button>
          )}
          {canApproveOrReject && (
            <>
              <Button className='rounded-full' onClick={() => onAction('approve')}>
                Aprobar
              </Button>
              <Button
                variant='outline'
                className='rounded-full text-destructive hover:text-destructive'
                onClick={() => onAction('reject')}
              >
                Rechazar
              </Button>
            </>
          )}
          {canCompleteOrNoShow && (
            <>
              <Button
                variant='outline'
                className='rounded-full'
                onClick={() => onAction('complete')}
              >
                Completar
              </Button>
              <Button
                variant='outline'
                className='rounded-full'
                onClick={() => onAction('no_show')}
              >
                No se presentó
              </Button>
            </>
          )}
          {canCancel && (
            <Button
              variant='outline'
              className='rounded-full text-destructive hover:text-destructive'
              onClick={() => onAction('cancel')}
            >
              Cancelar reservación
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

// ─── data hook ───────────────────────────────────────────────────────────────

/**
 * BUG FIX (E2E QA round 2): a resident opening their own reservation's detail
 * lacks `read.payment`, so fetching this list unconditionally hit
 * `POST /payment/search` → 403 → the global "Sin permiso" alert dialog. Gate
 * the fetch itself behind `canReadPayments` (also cheaper — no wasted
 * request) instead of fetching-then-hiding.
 */
function useReservationDetailData(id: string, canReadPayments: boolean) {
  const {
    entity: reservation,
    isLoading,
    isError,
    isSuccess,
    notFound,
    refetch,
  } = useEntityDetail<IAmenityReservation>({
    queryKey: ['amenity-reservation', 'detail', id],
    queryFn: () => AmenityReservationResource.show(id, { include: DETAIL_INCLUDE }),
    enabled: Boolean(id),
  })

  const {
    data: paymentsResponse,
    isLoading: paymentsLoading,
    refetch: refetchPayments,
  } = useQuery({
    queryKey: ['reservation-payments', id],
    queryFn: () =>
      PaymentResource.search({
        filters: [
          { field: 'earmark_type', operator: '=', value: EARMARK_TYPE.AMENITY_RESERVATION },
          { field: 'earmark_id', operator: '=', value: id },
        ],
        includes: [
          { relation: 'payment_status' },
          { relation: 'payment_method' },
          { relation: 'media' },
        ],
        sort: [{ field: 'paid_at', direction: 'desc' }],
      }),
    enabled: Boolean(id) && isSuccess && canReadPayments,
  })

  return {
    reservation,
    isLoading,
    isError,
    notFound,
    refetch,
    payments: paymentsResponse?.data ?? [],
    paymentsLoading,
    // `refetch()` bypasses `enabled` if called manually — re-guard here so a
    // non-privileged viewer's refreshAll() can't sneak the request through.
    refetchPayments: () => {
      if (canReadPayments) void refetchPayments()
    },
  }
}

// ─── main component ──────────────────────────────────────────────────────────

/**
 * Detalle admin de una reservación de amenidad (S13a): resumen, acciones FSM
 * (aprobar/rechazar/cancelar/completar/no-show), registro de pago (earmark
 * reutilizando `payment/register`), pagos vinculados (validar/rechazar/cancelar
 * reutilizando `usePaymentActions`/`PaymentActionDialogs` SIN modificarlos) y
 * cargos adicionales (S9).
 */
export const AmenityReservationDetailPage = () => {
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canManage = usePermissions('update.amenity_reservation')
  const canReadPayments = usePermissions('read.payment')

  const [surchargeOpen, setSurchargeOpen] = useState(false)
  const [registerPaymentOpen, setRegisterPaymentOpen] = useState(false)

  const {
    reservation,
    isLoading,
    isError,
    notFound,
    refetch,
    payments,
    paymentsLoading,
    refetchPayments,
  } = useReservationDetailData(id, canReadPayments)

  const refreshAll = () => {
    refetch()
    refetchPayments()
    void queryClient.invalidateQueries({ queryKey: ['reservation-payments', id] })
  }

  const reservationActions = useReservationActions({ reservation, onActionSuccess: refreshAll })
  const paymentActions = usePaymentActions({ onActionSuccess: refreshAll })

  if (isLoading) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando reservación…</p>
  }

  if (isError || !reservation) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Reservación no encontrada' : undefined}
        description={notFound ? 'La reservación no existe o no tienes acceso a ella.' : undefined}
        backTo='/amenity-reservation'
        backLabel='Volver a reservaciones'
        onRetry={notFound ? undefined : refetch}
      />
    )
  }

  // BUG FIX (E2E QA round 2 — residual de round 1): en estatuses terminales
  // `amount_outstanding` puede quedar congelado en un valor > 0 (ver
  // ReservationService::cancel()/expire()); mostrarlo confunde al usuario
  // sobre una reservación muerta, así que se pinta $0 sin importar el valor
  // almacenado (las acciones de pago ya estaban gateadas por status desde el
  // fix de round 1, vía RESERVATION_PAYABLE_STATUSES abajo).
  const outstandingMinor = isTerminalReservationStatus(reservation.reservation_status_id)
    ? 0
    : moneyToMinorUnits(reservation.amount_outstanding ?? 0)
  const canSurcharge =
    canManage && RESERVATION_SURCHARGEABLE_STATUSES.includes(reservation.reservation_status_id)

  return (
    <div className='space-y-5'>
      <Button
        variant='ghost'
        className='-ml-2 gap-1.5'
        onClick={() => navigate('/amenity-reservation')}
      >
        <ArrowLeft className='size-4' />
        Volver a reservaciones
      </Button>

      <ReservationHeader
        reservation={reservation}
        canManage={canManage}
        outstandingMinor={outstandingMinor}
        onAction={reservationActions.request}
        onRegisterPayment={() => setRegisterPaymentOpen(true)}
      />

      <section className='rounded-[16px] border border-border bg-card p-5 shadow-sm'>
        <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Saldo pendiente
        </div>
        <div className='mt-1 text-[30px] font-semibold tabular-nums'>
          {displayMoney(outstandingMinor)}
        </div>

        <dl className='mt-5 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3'>
          <DetailField label='Amenidad'>{reservation.amenity?.name ?? '—'}</DetailField>
          <DetailField label='Unidad'>
            {reservation.unit?.full_identifier ?? reservation.unit?.number ?? '—'}
          </DetailField>
          <DetailField label='Fecha'>
            {displayFormatDate(reservation.reservation_date, 'mediumDate')}
          </DetailField>
          {reservation.slot && (
            <DetailField label='Horario'>
              {formatTimeHHmm(reservation.slot.start_time)} –{' '}
              {formatTimeHHmm(reservation.slot.end_time)}
            </DetailField>
          )}
          {reservation.cost_amount != null && (
            <DetailField label='Costo'>{displayMoney(reservation.cost_amount)}</DetailField>
          )}
          {reservation.deposit_amount != null && (
            <DetailField label='Depósito'>{displayMoney(reservation.deposit_amount)}</DetailField>
          )}
          {reservation.payment_deadline && (
            <DetailField label='Fecha límite de pago'>
              {displayFormatDate(reservation.payment_deadline, 'mediumDate')}
            </DetailField>
          )}
          {reservation.balance_due_date && (
            <DetailField label='Fecha límite de saldo'>
              {displayFormatDate(reservation.balance_due_date, 'mediumDate')}
            </DetailField>
          )}
        </dl>

        {reservation.rejected_reason && (
          <div className='mt-4 rounded-[12px] border border-[#F0D6CE] bg-[#FBEEE9] px-4 py-3'>
            <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[#C0533C]'>
              Motivo de rechazo
            </div>
            <p className='mt-1 text-[13px] text-foreground'>{reservation.rejected_reason}</p>
          </div>
        )}

        {reservation.cancellation_reason && (
          <div className='mt-4 rounded-[12px] border border-[#F0D6CE] bg-[#FBEEE9] px-4 py-3'>
            <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[#C0533C]'>
              Motivo de cancelación
            </div>
            <p className='mt-1 text-[13px] text-foreground'>{reservation.cancellation_reason}</p>
          </div>
        )}
      </section>

      {canReadPayments && (
        <PaymentsCard
          payments={payments}
          isLoading={paymentsLoading}
          canManage={canManage}
          paymentActions={paymentActions}
        />
      )}

      <SurchargesCard
        surcharges={reservation.surcharges ?? []}
        canManage={canSurcharge}
        onAdd={() => setSurchargeOpen(true)}
      />

      <ReservationActionDialogs dialog={reservationActions.dialog} />
      <PaymentActionDialogs dialog={paymentActions.dialog} />

      <AddSurchargeDialog
        isOpen={surchargeOpen}
        onClose={() => setSurchargeOpen(false)}
        reservationId={reservation.id}
        onCreated={refreshAll}
      />

      <RegisterReservationPaymentSheet
        isOpen={registerPaymentOpen}
        onClose={() => setRegisterPaymentOpen(false)}
        reservation={reservation}
        amenity={reservation.amenity ?? { bank_account_id: null, bank_account: null }}
        onRegistered={refreshAll}
      />
    </div>
  )
}
