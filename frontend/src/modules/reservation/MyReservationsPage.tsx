import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { ReservationActionDialogs } from '@/modules/reservation/components/ReservationActionDialogs'
import { SubmitReservationPaymentSheet } from '@/modules/reservation/components/SubmitReservationPaymentSheet'
import { useReservationActions } from '@/modules/reservation/hooks/useReservationActions'
import {
  RESERVATION_ACTIVE_STATUSES,
  RESERVATION_PAYABLE_STATUSES,
  displayableOutstandingAmount,
  reservationPaymentDueDate,
  reservationStatusTone,
  type IAmenityReservation,
} from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { Pagination } from '@/shared/components/Pagination'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { paginationFromMeta } from '@/shared/hooks/baseListParams'
import type { IPaginationMeta } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

const PAGE_SIZE = 20

/** "Vence el …" — formatea `reservationPaymentDueDate`, o un guion cuando no aplica. */
function dueDateLabel(reservation: IAmenityReservation): string {
  const dueDate = reservationPaymentDueDate(reservation)
  return dueDate ? displayFormatDate(dueDate, 'mediumDate') : '—'
}

function ReservationRowActions({
  reservation,
  onCancel,
  onPay,
}: {
  reservation: IAmenityReservation
  onCancel: (_r: IAmenityReservation) => void
  onPay: (_r: IAmenityReservation) => void
}) {
  const canCancel = RESERVATION_ACTIVE_STATUSES.includes(reservation.reservation_status_id)
  const isPayableStatus = RESERVATION_PAYABLE_STATUSES.includes(reservation.reservation_status_id)
  const hasOutstandingBalance = moneyToMinorUnits(reservation.amount_outstanding ?? 0) > 0
  // BUG FIX (E2E QA): a cancelled/rejected/completed/no-show reservation can
  // still carry a non-zero amount_outstanding (frozen at cancellation time,
  // see ReservationService::cancel()) — gate by status too, not just the
  // balance, so a dead reservation never shows a "Pagar" button.
  // A pending-validation earmark payment already exists — block a second
  // submission (the backend would 422 it) and show "Pago en revisión" instead.
  const canPay = isPayableStatus && hasOutstandingBalance && !reservation.has_pending_payment
  const showPendingPaymentPill =
    isPayableStatus && hasOutstandingBalance && Boolean(reservation.has_pending_payment)

  if (!canCancel && !canPay && !showPendingPaymentPill) return null

  return (
    <div className='flex justify-end gap-2'>
      {showPendingPaymentPill && <StatusPill tone='y'>Pago en revisión</StatusPill>}
      {canPay && (
        <Button size='sm' onClick={() => onPay(reservation)}>
          Pagar
        </Button>
      )}
      {canCancel && (
        <Button
          size='sm'
          variant='outline'
          className='text-destructive hover:text-destructive'
          onClick={() => onCancel(reservation)}
        >
          Cancelar
        </Button>
      )}
    </div>
  )
}

/**
 * "Mis reservaciones" del residente (S16): reservaciones de SUS unidades
 * (filtradas por `unit_id in [...]` — ver nota de infra abajo), con acciones
 * cancelar (reutiliza `useReservationActions`/`ReservationActionDialogs`,
 * compartidas con el detalle admin) y pagar saldo (reutiliza
 * `SubmitReservationPaymentSheet`, earmark).
 *
 * El backend aplica `ScopesToResidentUnits` en `AmenityReservationController`
 * (index/show quedan acotados a las unidades propias del residente), así que
 * la seguridad NO depende del cliente. El filtro `unit_id in [...]` de abajo
 * es solo una conveniencia de UX (permite elegir entre las propias unidades
 * del residente cuando tiene más de una), no el límite de seguridad.
 */
export const MyReservationsPage = () => {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [limit, setLimit] = useState(PAGE_SIZE)
  const [cancelTarget, setCancelTarget] = useState<IAmenityReservation | null>(null)
  const [payTarget, setPayTarget] = useState<IAmenityReservation | null>(null)

  const { data: ownUnitsData, isSuccess: ownUnitsLoaded } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })
  const unitIds = useMemo(() => (ownUnitsData ?? []).map((u) => u.id), [ownUnitsData])

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['my-reservations', unitIds, page, limit],
    queryFn: () =>
      AmenityReservationResource.search({
        page,
        limit,
        sort: [{ field: 'reservation_date', direction: 'desc' }],
        includes: [{ relation: 'amenity' }, { relation: 'reservation_status' }],
        filters: [{ field: 'unit_id', operator: 'in', value: unitIds }],
      }),
    enabled: unitIds.length > 0,
  })

  const { data: payAmenity } = useQuery({
    queryKey: ['amenity', 'bank-account', payTarget?.amenity_id],
    queryFn: () => AmenityResource.show(payTarget!.amenity_id, { include: 'bank_account' }),
    enabled: Boolean(payTarget),
  })

  const reservations = data?.data ?? []
  const pagination = paginationFromMeta(
    (data?.meta ?? {}) as IPaginationMeta,
    { page, limit },
    reservations.length,
    data?.total
  )

  const refreshAll = () => {
    void refetch()
    void queryClient.invalidateQueries({ queryKey: ['my-reservations'] })
  }

  const reservationActions = useReservationActions({
    reservation: cancelTarget ?? undefined,
    onActionSuccess: () => {
      refreshAll()
      setCancelTarget(null)
    },
  })

  const requestCancel = (reservation: IAmenityReservation) => {
    setCancelTarget(reservation)
    reservationActions.request('cancel')
  }

  const isPageLoading = !ownUnitsLoaded || (unitIds.length > 0 && isLoading)

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Mis reservaciones'
        description='Reservaciones de amenidades de tus unidades'
        actions={
          <>
            {/* S18: calendario de ocupación ANONIMIZADO (sin unidad ni residente) —
            transparencia de qué amenidades están ocupadas en todo el clúster, útil
            tanto para el residente como para el comité de vigilancia (que aterriza
            en esta misma página al no tener update.amenity_reservation). */}
            <Button
              variant='outline'
              className='rounded-full'
              onClick={() => navigate('/amenity-occupancy')}
            >
              <CalendarDays className='size-4' />
              Ver calendario de ocupación
            </Button>
          </>
        }
      />

      {isPageLoading ? (
        <TableLoadingState />
      ) : reservations.length === 0 ? (
        <TableEmptyState
          title='Sin reservaciones'
          description='Todavía no has reservado ninguna amenidad.'
        />
      ) : (
        <>
          <ResponsiveDataTable<IAmenityReservation>
            rows={reservations}
            header={
              <TableHeader>
                <TableRow>
                  <TableHead scope='col'>Amenidad</TableHead>
                  <TableHead scope='col'>Fecha</TableHead>
                  <TableHead scope='col'>Estado</TableHead>
                  <TableHead scope='col'>Vence</TableHead>
                  <TableHead scope='col' className='text-right'>
                    Saldo pendiente
                  </TableHead>
                  <TableHead scope='col' className='text-right'></TableHead>
                </TableRow>
              </TableHeader>
            }
            renderRow={(reservation) => (
              <TableRow key={reservation.id}>
                <TableCell className='font-medium'>{reservation.amenity?.name ?? '—'}</TableCell>
                <TableCell>
                  {displayFormatDate(reservation.reservation_date, 'mediumDate')}
                </TableCell>
                <TableCell>
                  <StatusPill tone={reservationStatusTone(reservation.reservation_status_id)}>
                    {reservation.reservation_status?.name ?? '—'}
                  </StatusPill>
                </TableCell>
                <TableCell>{dueDateLabel(reservation)}</TableCell>
                <TableCell className='text-right font-semibold tabular-nums'>
                  {displayMoney(
                    displayableOutstandingAmount(
                      reservation.reservation_status_id,
                      reservation.amount_outstanding
                    )
                  )}
                </TableCell>
                <TableCell className='text-right'>
                  <ReservationRowActions
                    reservation={reservation}
                    onCancel={requestCancel}
                    onPay={setPayTarget}
                  />
                </TableCell>
              </TableRow>
            )}
            renderCardTitle={(reservation) => reservation.amenity?.name ?? '—'}
            renderCardFields={(reservation) => [
              {
                label: 'Fecha',
                value: displayFormatDate(reservation.reservation_date, 'mediumDate'),
              },
              {
                label: 'Estado',
                value: (
                  <StatusPill tone={reservationStatusTone(reservation.reservation_status_id)}>
                    {reservation.reservation_status?.name ?? '—'}
                  </StatusPill>
                ),
              },
              {
                label: 'Vence',
                value: dueDateLabel(reservation),
              },
              {
                label: 'Saldo pendiente',
                value: (
                  <span className='font-semibold'>
                    {displayMoney(
                      displayableOutstandingAmount(
                        reservation.reservation_status_id,
                        reservation.amount_outstanding
                      )
                    )}
                  </span>
                ),
              },
            ]}
            renderActions={(reservation) => (
              <ReservationRowActions
                reservation={reservation}
                onCancel={requestCancel}
                onPay={setPayTarget}
              />
            )}
          />
          <Pagination
            pagination={pagination}
            onPageChanged={(nextPage, nextLimit) => {
              setPage(nextPage)
              if (nextLimit) setLimit(nextLimit)
            }}
          />
        </>
      )}

      <ReservationActionDialogs dialog={reservationActions.dialog} />

      {payTarget && payAmenity && (
        <SubmitReservationPaymentSheet
          isOpen
          onClose={() => setPayTarget(null)}
          reservation={payTarget}
          amenity={payAmenity}
          onSubmitted={() => {
            refreshAll()
            setPayTarget(null)
            navigate('/my-reservations')
          }}
        />
      )}
    </div>
  )
}
