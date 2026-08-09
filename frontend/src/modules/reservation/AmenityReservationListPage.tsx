import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { AdminCreateReservationSheet } from '@/modules/reservation/components/AdminCreateReservationSheet'
import { ReservationFiltersPanel } from '@/modules/reservation/components/ReservationFiltersPanel'
import {
  buildReservationFilters,
  INITIAL_RESERVATION_FILTERS,
  type ReservationFilters,
} from '@/modules/reservation/reservationFilters'
import { reservationStatusTone, type IAmenityReservation } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { useAmenityReservationStore } from '@/modules/reservation/store/amenityReservationStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { CalendarDays, Plus } from 'lucide-react'
import { useCallback, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

/**
 * Listado admin de reservaciones de amenidades (S13a): amenidad, unidad,
 * fecha, estado (pill coloreado por `reservation_status`) y saldo pendiente.
 * Filtros por estado y amenidad. Las acciones del ciclo de vida (aprobar/
 * rechazar/cancelar/completar/no-show), el registro de pago y los cargos
 * adicionales viven en el detalle.
 *
 * S17 menu wiring: the sidebar's "Reservaciones" entry always points here
 * (single module row, plain 'amenity_reservation' permission_entity). A
 * viewer without update.amenity_reservation (residents/renters, oversight
 * committee) has no lifecycle actions to take on this inbox and is sent to
 * their own reservations instead — mirrors PackageListPage's redirect to
 * /my-packages.
 *
 * "Nueva reservación" opens `AdminCreateReservationSheet` — an admin/board
 * create-on-behalf flow for ANY cluster unit (not just the caller's own),
 * gated by the same update.amenity_reservation the whole page requires.
 */
export const AmenityReservationListPage = () => {
  const navigate = useNavigate()
  const canManage = usePermissions('update.amenity_reservation')
  const openDetail = useCallback(
    (reservation: IAmenityReservation) => navigate(`/amenity-reservation/${reservation.id}`),
    [navigate]
  )

  const [filters, setFilters] = useState<ReservationFilters>(INITIAL_RESERVATION_FILTERS)
  const [createOpen, setCreateOpen] = useState(false)

  const { onPageChanged, onSearch, paramsSearch } = useBaseList<IAmenityReservation>({
    store: useAmenityReservationStore,
    resource: AmenityReservationResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'reservation_date', direction: 'desc' }],
      includes: [{ relation: 'amenity' }, { relation: 'unit' }, { relation: 'reservation_status' }],
    },
  })
  const { entities, pagination, isLoading } = useAmenityReservationStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  const runSearch = useCallback(
    (panel: ReservationFilters, clearSearch = false) => {
      onSearch({
        ...paramsSearch,
        page: 1,
        ...(clearSearch ? { search: null } : {}),
        filters: buildReservationFilters(panel),
      })
    },
    [onSearch, paramsSearch]
  )

  const applyFilters = useCallback(
    (next: ReservationFilters) => {
      setFilters(next)
      runSearch(next)
    },
    [runSearch]
  )

  const resetFilters = useCallback(() => {
    setFilters(INITIAL_RESERVATION_FILTERS)
    runSearch(INITIAL_RESERVATION_FILTERS, true)
  }, [runSearch])

  if (!canManage) {
    return <Navigate to='/my-reservations' replace />
  }

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Reservaciones'
        description='Reservaciones de amenidades del condominio'
        actions={
          <>
            <Button
              variant='outline'
              className='rounded-full'
              onClick={() => navigate('/amenity-reservation/calendar')}
            >
              <CalendarDays className='size-4' />
              Ver calendario
            </Button>
            <Button className='rounded-full' onClick={() => setCreateOpen(true)}>
              <Plus className='size-4' />
              Nueva reservación
            </Button>
          </>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={() => resetFilters()}
          placeholder='Buscar reservaciones…'
          defaultFiltersExpanded={false}
          toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
        >
          <ReservationFiltersPanel filters={filters} onChange={applyFilters} />
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState title='Sin reservaciones registradas' />
        ) : (
          <>
            <ResponsiveDataTable<IAmenityReservation>
              rows={entities}
              onRowClick={openDetail}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Amenidad</TableHead>
                    <TableHead scope='col'>Unidad</TableHead>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Saldo pendiente
                    </TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(reservation) => (
                <TableRow
                  key={reservation.id}
                  className='cursor-pointer'
                  onClick={() => openDetail(reservation)}
                >
                  <TableCell className='font-medium'>{reservation.amenity?.name ?? '—'}</TableCell>
                  <TableCell>
                    {reservation.unit?.full_identifier ?? reservation.unit?.number ?? '—'}
                  </TableCell>
                  <TableCell>
                    {displayFormatDate(reservation.reservation_date, 'mediumDate')}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={reservationStatusTone(reservation.reservation_status_id)}>
                      {reservation.reservation_status?.name ?? '—'}
                    </StatusPill>
                  </TableCell>
                  <TableCell className='text-right font-semibold tabular-nums'>
                    {displayMoney(reservation.amount_outstanding ?? 0)}
                  </TableCell>
                </TableRow>
              )}
              renderCardTitle={(reservation) => reservation.amenity?.name ?? '—'}
              renderCardFields={(reservation) => [
                {
                  label: 'Unidad',
                  value: reservation.unit?.full_identifier ?? reservation.unit?.number ?? '—',
                },
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
                  label: 'Saldo pendiente',
                  value: (
                    <span className='font-semibold'>
                      {displayMoney(reservation.amount_outstanding ?? 0)}
                    </span>
                  ),
                },
              ]}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <AdminCreateReservationSheet isOpen={createOpen} onClose={() => setCreateOpen(false)} />
    </div>
  )
}
