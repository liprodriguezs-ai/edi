import { Button } from '@/components/ui/button'
import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import type { IAnnouncement } from '@/modules/announcement/schema'
import { VisitorFormModal } from '@/modules/access/components/VisitorFormModal'
import { VisitorQrModal } from '@/modules/access/components/VisitorQrModal'
import type { IAccessLog, IVisitor } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import type { StatementResponse } from '@/modules/payment/schema'
import {
  RESERVATION_PAYABLE_STATUSES,
  displayableOutstandingAmount,
  reservationStatusTone,
  type IAmenityReservation,
} from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { sessionStore } from '@/shared/auth/sessionStore'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { formatMinorUnits, moneyToMinorUnits } from '@/shared/utils/money'
import { useQueries, useQuery } from '@tanstack/react-query'
import {
  AlertCircle,
  CalendarDays,
  CalendarPlus,
  DoorOpen,
  Megaphone,
  UserPlus,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useStore } from 'zustand'

const CARD = 'rounded-[14px] border border-border bg-card p-4 shadow-sm'
const PANEL = 'overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'
const PANEL_LINK = 'text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'

/** true si el instante ISO cae en el día LOCAL de hoy (comparación, no formato). */
function isToday(dateStr?: string | null): boolean {
  if (!dateStr) return false
  const date = new Date(dateStr)
  if (Number.isNaN(date.getTime())) return false
  const now = new Date()
  return (
    date.getFullYear() === now.getFullYear() &&
    date.getMonth() === now.getMonth() &&
    date.getDate() === now.getDate()
  )
}

/** Tarjetas de adeudo/saldo/pagado del residente, agregadas sobre TODAS sus unidades. */
function FinancialSummary({ statements }: { statements: StatementResponse[] }) {
  const totals = useMemo(
    () =>
      statements.reduce(
        (acc, s) => {
          acc.outstanding += moneyToMinorUnits(s.totals?.outstanding)
          acc.credit += moneyToMinorUnits(s.credit_balance)
          acc.paid += moneyToMinorUnits(s.totals?.paid)
          return acc
        },
        { outstanding: 0, credit: 0, paid: 0 }
      ),
    [statements]
  )
  const outstandingColor = totals.outstanding > 0 ? 'text-[#C0533C]' : 'text-foreground'

  return (
    <div className='grid grid-cols-1 gap-3.5 sm:grid-cols-3'>
      <div className={CARD}>
        <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Adeudo total
        </div>
        <div className={`mt-1 text-[24px] font-semibold tabular-nums ${outstandingColor}`}>
          {formatMinorUnits(totals.outstanding)}
        </div>
      </div>
      <div className={CARD}>
        <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Saldo a favor
        </div>
        <div className='mt-1 text-[24px] font-semibold tabular-nums text-[#3F6E8E]'>
          {formatMinorUnits(totals.credit)}
        </div>
      </div>
      <div className={CARD}>
        <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Pagado histórico
        </div>
        <div className='mt-1 text-[24px] font-semibold tabular-nums'>
          {formatMinorUnits(totals.paid)}
        </div>
      </div>
    </div>
  )
}

/**
 * Panel de amenidades del residente: reservaciones próximas/activas de sus unidades,
 * con una alerta destacada para las pendientes de pago. El dashboard AVISA; el flujo
 * de pago vive en "Mis reservaciones" (/my-reservations), que enlazamos aquí.
 */
function AmenityReservationsPanel({ reservations }: { reservations: IAmenityReservation[] }) {
  const pendingPaymentCount = reservations.filter(
    (r) =>
      RESERVATION_PAYABLE_STATUSES.includes(r.reservation_status_id) &&
      moneyToMinorUnits(r.amount_outstanding ?? 0) > 0
  ).length

  return (
    <div className={PANEL}>
      <div className='flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3.5'>
        <h3 className='flex items-center gap-2 text-sm font-semibold'>
          <CalendarDays className='size-4 text-[var(--ink-3)]' aria-hidden />
          Mis reservaciones
        </h3>
        <Link to='/my-reservations' className={PANEL_LINK}>
          Ver todas →
        </Link>
      </div>

      {pendingPaymentCount > 0 && (
        <Link
          to='/my-reservations'
          className='flex items-center gap-2.5 border-b border-[var(--line-2)] bg-[#F6E2DC] px-4 py-3 text-[13px] text-[#8A3A28] hover:bg-[#F1D7CF]'
        >
          <AlertCircle className='size-4 flex-none' aria-hidden />
          <span>
            <b className='font-semibold'>
              {pendingPaymentCount}{' '}
              {pendingPaymentCount === 1 ? 'reservación pendiente' : 'reservaciones pendientes'} de
              pago.
            </b>{' '}
            Págalas para confirmarlas.
          </span>
        </Link>
      )}

      {reservations.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          No tienes reservaciones activas.{' '}
          <Link to='/amenities' className='font-medium text-[var(--green-deep)] hover:underline'>
            Reserva una amenidad
          </Link>
          .
        </p>
      ) : (
        <ul role='list'>
          {reservations.map((reservation) => {
            const outstanding = moneyToMinorUnits(
              displayableOutstandingAmount(
                reservation.reservation_status_id,
                reservation.amount_outstanding
              )
            )
            return (
              <li
                key={reservation.id}
                className='flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
              >
                <div className='min-w-0 flex-1'>
                  <p className='truncate text-[13px] font-semibold text-foreground'>
                    {reservation.amenity?.name ?? '—'}
                  </p>
                  <p className='text-[11.5px] text-[var(--ink-3)]'>
                    {displayFormatDate(reservation.reservation_date, 'mediumDate')}
                    {outstanding > 0 && ` · Saldo ${formatMinorUnits(outstanding)}`}
                  </p>
                </div>
                <StatusPill tone={reservationStatusTone(reservation.reservation_status_id)}>
                  {reservation.reservation_status?.name ?? '—'}
                </StatusPill>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

/** Feed de avisos del condominio dirigidos al residente (GET /announcement/board). */
function AnnouncementsPanel({ announcements }: { announcements: IAnnouncement[] }) {
  return (
    <div className={PANEL}>
      <div className='flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3.5'>
        <h3 className='flex items-center gap-2 text-sm font-semibold'>
          <Megaphone className='size-4 text-[var(--ink-3)]' aria-hidden />
          Avisos recientes
        </h3>
        <Link to='/announcement' className={PANEL_LINK}>
          Ver todos →
        </Link>
      </div>

      {announcements.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          No hay avisos recientes.
        </p>
      ) : (
        <ul role='list'>
          {announcements.map((announcement) => (
            <li key={announcement.id} className='border-b border-[var(--line-2)] last:border-b-0'>
              <Link
                to={`/announcement/${announcement.id}`}
                className='flex items-start gap-2.5 px-4 py-3 hover:bg-[var(--surface-2)]'
              >
                {!announcement.is_read && (
                  <span
                    className='mt-1.5 size-2 flex-none rounded-full bg-[var(--green)]'
                    aria-label='No leído'
                  />
                )}
                <div className={`min-w-0 flex-1 ${announcement.is_read ? 'pl-[18px]' : ''}`}>
                  <p className='truncate text-[13px] font-semibold text-foreground'>
                    {announcement.title}
                  </p>
                  <p className='text-[11.5px] text-[var(--ink-3)]'>
                    {announcement.priority?.name ? `${announcement.priority.name} · ` : ''}
                    {displayFormatDate(
                      announcement.publish_at ?? announcement.created_at,
                      'mediumDate'
                    )}
                  </p>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** Estado visual de un registro de bitácora (espeja MyAccessLogPage). */
function AccessStatus({ log }: { log: IAccessLog }) {
  if (log.denied_at) return <StatusPill tone='r'>Denegado</StatusPill>
  if (log.exited_at) return <StatusPill tone='b'>Salió</StatusPill>
  return <StatusPill tone='g'>Dentro</StatusPill>
}

/** Accesos de HOY a las unidades del residente (subconjunto de /access/my-log). */
function TodayAccessPanel({ logs }: { logs: IAccessLog[] }) {
  const todays = logs.filter(
    (log) => isToday(log.entered_at) || isToday(log.exited_at) || isToday(log.denied_at)
  )

  return (
    <div className={PANEL}>
      <div className='flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3.5'>
        <h3 className='flex items-center gap-2 text-sm font-semibold'>
          <DoorOpen className='size-4 text-[var(--ink-3)]' aria-hidden />
          Accesos de hoy
        </h3>
        <Link to='/my-access-log' className={PANEL_LINK}>
          Ver historial →
        </Link>
      </div>

      {todays.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          Sin accesos registrados hoy.
        </p>
      ) : (
        <ul role='list'>
          {todays.map((log) => (
            <li
              key={log.id}
              className='flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
            >
              <div className='min-w-0 flex-1'>
                <p className='truncate text-[13px] font-semibold text-foreground'>
                  {log.visitor_name ?? log.visitor?.name ?? 'Visitante'}
                </p>
                <p className='text-[11.5px] text-[var(--ink-3)]'>
                  {log.unit?.full_identifier ?? log.unit?.number ?? '—'}
                  {log.entered_at && ` · Entró ${displayFormatDate(log.entered_at)}`}
                </p>
              </div>
              {log.plate && (
                <span className='rounded border border-border bg-[var(--surface-2)] px-1.5 font-mono text-[12px]'>
                  {log.plate}
                </span>
              )}
              <AccessStatus log={log} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * Home del RESIDENTE (roles sin acceso a finanzas del clúster). Muestra LO SUYO:
 * resumen de adeudo/saldo/pagado de sus unidades, sus reservaciones de amenidades
 * (con alerta de pendientes de pago), los avisos del condominio y los accesos de
 * hoy. Reemplaza al panel del tesorero, que para un residente quedaba vacío.
 */
export function ResidentHome() {
  const profile = useStore(sessionStore, (s) => s.profile)
  const firstName = profile?.user?.name?.split(' ')[0] ?? ''
  const tenantName = profile?.tenant?.name

  const [isVisitorFormOpen, setIsVisitorFormOpen] = useState(false)
  const [createdVisitor, setCreatedVisitor] = useState<IVisitor | null>(null)

  const { data: ownUnits = [] } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })
  const unitIds = useMemo(() => ownUnits.map((u) => u.id), [ownUnits])

  // Estado de cuenta por unidad (reusa la key/endpoint de la pantalla Estado de cuenta).
  const statementQueries = useQueries({
    queries: ownUnits.map((u) => ({
      queryKey: ['statement', u.id],
      queryFn: () => PaymentResource.statement(u.id),
      enabled: Boolean(u.id),
    })),
  })
  const statements = statementQueries
    .map((q) => q.data)
    .filter((s): s is StatementResponse => Boolean(s))

  // Reservaciones ACTIVAS (próximas primero). Alcance real por el backend
  // (ScopesToResidentUnits); el filtro por unit_id es conveniencia de UX.
  const { data: reservationData } = useQuery({
    queryKey: ['my-reservations', 'dashboard', unitIds],
    queryFn: () =>
      AmenityReservationResource.search({
        limit: 5,
        sort: [{ field: 'reservation_date', direction: 'asc' }],
        includes: [{ relation: 'amenity' }, { relation: 'reservation_status' }],
        filters: [{ field: 'unit_id', operator: 'in', value: unitIds }],
      }),
    enabled: unitIds.length > 0,
  })
  const reservations = reservationData?.data ?? []

  const { data: announcements = [] } = useQuery({
    queryKey: ['announcement', 'board'],
    queryFn: () => AnnouncementResource.board(),
  })

  // Bitácora del residente (primera página; el panel filtra a HOY en cliente).
  const { data: accessLog } = useQuery({
    queryKey: ['access', 'my-log', 1],
    queryFn: () => AccessLogResource.myLog(1),
    staleTime: 60_000,
  })
  const accessLogs = accessLog?.data ?? []

  return (
    <div className='space-y-5'>
      <div className='flex flex-wrap items-start justify-between gap-4'>
        <div>
          <div className='mb-[7px] text-[10.5px] font-semibold uppercase tracking-[0.15em] text-[var(--ink-4)]'>
            {tenantName ?? 'Tu condominio'}
          </div>
          <h1 className='text-[30px] font-semibold leading-[1.12] tracking-[-0.02em]'>
            {firstName ? (
              <>
                Hola, <b className='font-semibold text-[var(--green)]'>{firstName}</b>
              </>
            ) : (
              <>
                Tu <b className='font-semibold text-[var(--green)]'>resumen</b>
              </>
            )}
          </h1>
          <div className='mt-1.5 text-[13.5px] text-[var(--ink-3)]'>
            Esto es lo que pasa en tu unidad y tu condominio
          </div>
        </div>
        <div className='flex flex-wrap items-center gap-2'>
          <PermissionGuard permission='create.amenity_reservation'>
            <Button asChild variant='outline' className='rounded-full'>
              <Link to='/amenities'>
                <CalendarPlus className='size-4' />
                Reservar amenidad
              </Link>
            </Button>
          </PermissionGuard>
          <PermissionGuard permission='create.visitor'>
            <Button className='rounded-full' onClick={() => setIsVisitorFormOpen(true)}>
              <UserPlus className='size-4' />
              Registrar visita
            </Button>
          </PermissionGuard>
        </div>
      </div>

      {ownUnits.length > 0 && (
        <div className='space-y-2'>
          <FinancialSummary statements={statements} />
          <div className='text-right'>
            <Link to='/statement' className={PANEL_LINK}>
              Ver estado de cuenta completo →
            </Link>
          </div>
        </div>
      )}

      <div className='grid grid-cols-1 gap-3.5 lg:grid-cols-2'>
        <AmenityReservationsPanel reservations={reservations} />
        <AnnouncementsPanel announcements={announcements} />
      </div>

      <TodayAccessPanel logs={accessLogs} />

      {/* Montaje condicional: VisitorFormModal carga catálogos (/selects) sin gate,
          así solo dispara al abrir — no en cada carga del dashboard (§2.12 infra). */}
      {isVisitorFormOpen && (
        <VisitorFormModal
          isOpen
          onClose={() => setIsVisitorFormOpen(false)}
          onCreated={(visitor) => {
            setIsVisitorFormOpen(false)
            setCreatedVisitor(visitor)
          }}
        />
      )}
      <VisitorQrModal visitor={createdVisitor} onClose={() => setCreatedVisitor(null)} />
    </div>
  )
}
