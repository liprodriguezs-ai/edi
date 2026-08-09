import { Button } from '@/components/ui/button'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PaymentActionButtons } from '@/modules/payment/components/PaymentActionButtons'
import { PaymentActionDialogs } from '@/modules/payment/components/PaymentActionDialogs'
import { PaymentFiltersPanel } from '@/modules/payment/components/PaymentFiltersPanel'
import { RegisterPaymentSheet } from '@/modules/payment/components/RegisterPaymentSheet'
import { usePaymentActions } from '@/modules/payment/hooks/usePaymentActions'
import {
  buildPaymentFilters,
  INITIAL_PAYMENT_FILTERS,
  type PaymentFilters,
} from '@/modules/payment/paymentFilters'
import {
  EARMARK_TYPE,
  PAYMENT_STATUS,
  paymentStatusTone,
  type IPayment,
} from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { usePaymentStore } from '@/modules/payment/store/paymentStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import type { Filter } from '@/shared/interfaces/list.types'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { Download, Loader2, Plus, Upload } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useShallow } from 'zustand/react/shallow'

type TabKey = 'all' | 'pending'

const receiptOf = (payment: IPayment) => payment.media?.find((m) => m.collection_name === 'receipt')

/**
 * Calcula qué acciones se muestran en cada fila de la lista. La visibilidad es
 * POR PESTAÑA (no por estado): la cola "Pendientes" expone validar/rechazar; el
 * resto expone cancelar (salvo pagos ya cancelados). El detalle, en cambio,
 * decide por `payment_status_id`.
 */
function rowVisibility(payment: IPayment, tab: TabKey) {
  return {
    download: Boolean(receiptOf(payment)),
    validate: tab === 'pending',
    reject: tab === 'pending',
    cancel: tab !== 'pending' && payment.payment_status_id !== PAYMENT_STATUS.CANCELLED,
  }
}

/**
 * Pagos (§8.5): listado general + cola de validación de pagos pendientes
 * (§8.5.3) con validar/rechazar, y captura de pago en slide-over.
 */
export const PaymentListPage = () => {
  const navigate = useNavigate()
  const openDetail = useCallback(
    (payment: IPayment) => navigate(`/payment/${payment.id}`),
    [navigate]
  )
  const [tab, setTab] = useState<TabKey>('all')
  const [registerOpen, setRegisterOpen] = useState(false)

  // Filter panel state (independent of the tabs; both combine with AND).
  const [filters, setFilters] = useState<PaymentFilters>(INITIAL_PAYMENT_FILTERS)

  const canValidate = usePermissions('update.payment')

  const { onPageChanged, onSearch, onReport, isExporting, paramsSearch } = useBaseList<IPayment>({
    store: usePaymentStore,
    resource: PaymentResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'paid_at', direction: 'desc' }],
      includes: [
        { relation: 'unit' },
        { relation: 'payment_status' },
        { relation: 'payment_method' },
        { relation: 'payer_user' },
        { relation: 'media' },
      ],
    },
  })
  const { entities, pagination, isLoading } = usePaymentStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  // La lista refresca re-ejecutando la búsqueda actual (useBaseList → store);
  // no usa una query key de TanStack, así que no hay que invalidar nada.
  const refresh = useCallback(() => {
    onSearch({ ...paramsSearch })
  }, [onSearch, paramsSearch])

  const {
    requestValidate,
    requestReject,
    requestCancel,
    downloadReceipt,
    downloadingId,
    validatePending,
    rejectPending,
    cancelPending,
    dialog,
  } = usePaymentActions({ onActionSuccess: refresh })

  /**
   * Combines the active tab constraint with the filter-panel state into a single
   * Orion filter array (AND semantics). The "Pendientes de validación" tab pins
   * payment_status_id; the panel's own Estado filter still applies on top.
   */
  const runSearch = useCallback(
    (tabKey: TabKey, panel: PaymentFilters, clearSearch = false) => {
      const tabFilters: Filter[] =
        tabKey === 'pending'
          ? [
              {
                field: 'payment_status_id',
                operator: '=',
                value: PAYMENT_STATUS.PENDING_VALIDATION,
              },
            ]
          : []
      onSearch({
        ...paramsSearch,
        page: 1,
        ...(clearSearch ? { search: null } : {}),
        filters: [...tabFilters, ...buildPaymentFilters(panel)],
      })
    },
    [onSearch, paramsSearch]
  )

  const switchTab = useCallback(
    (next: string) => {
      const key = next as TabKey
      setTab(key)
      runSearch(key, filters)
    },
    [runSearch, filters]
  )

  const applyFilters = useCallback(
    (next: PaymentFilters) => {
      setFilters(next)
      runSearch(tab, next)
    },
    [runSearch, tab]
  )

  // Limpiar: borra texto de búsqueda + panel de filtros, conservando la pestaña activa.
  const resetFilters = useCallback(() => {
    setFilters(INITIAL_PAYMENT_FILTERS)
    runSearch(tab, INITIAL_PAYMENT_FILTERS, true)
  }, [runSearch, tab])

  const renderActions = useCallback(
    (payment: IPayment) => (
      <PaymentActionButtons
        payment={payment}
        show={rowVisibility(payment, tab)}
        downloadingId={downloadingId}
        validatePending={validatePending}
        rejectPending={rejectPending}
        cancelPending={cancelPending}
        onDownload={() => downloadReceipt(payment)}
        onReject={() => requestReject(payment)}
        onValidate={() => requestValidate(payment)}
        onCancel={() => requestCancel(payment)}
      />
    ),
    [
      tab,
      downloadingId,
      validatePending,
      rejectPending,
      cancelPending,
      downloadReceipt,
      requestReject,
      requestValidate,
      requestCancel,
    ]
  )

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Pagos'
        description='Pagos registrados y pendientes de validación'
        actions={
          <>
            <PermissionGuard permission='report.payment'>
              <Button
                variant='outline'
                onClick={() => onReport('Payment', 'xlsx', 'pagos')}
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
            <PermissionGuard permission='create.data_import'>
              <Button
                variant='outline'
                onClick={() => navigate('/import')}
                className='flex items-center gap-2'
              >
                <Upload className='h-4 w-4' />
                Importar pagos
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.payment'>
              <Button className='rounded-full' onClick={() => setRegisterOpen(true)}>
                <Plus className='size-4' />
                Registrar pago
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <Tabs value={tab} onValueChange={switchTab}>
        <TabsList>
          <TabsTrigger value='all'>Todos</TabsTrigger>
          <TabsTrigger value='pending'>Pendientes de validación</TabsTrigger>
        </TabsList>
      </Tabs>

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={(_data) => resetFilters()}
          placeholder='Buscar por folio o referencia…'
          defaultFiltersExpanded={false}
          toggleLabels={{ expand: 'Mostrar filtros', collapse: 'Ocultar filtros' }}
        >
          <PaymentFiltersPanel filters={filters} onChange={applyFilters} />
        </SearchList>

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title={
              tab === 'pending' ? 'Sin pagos pendientes de validación' : 'Sin pagos registrados'
            }
          />
        ) : (
          <>
            <ResponsiveDataTable<IPayment>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Folio</TableHead>
                    <TableHead scope='col'>Unidad</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Monto
                    </TableHead>
                    <TableHead scope='col'>Método</TableHead>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {canValidate && <TableHead scope='col' className='text-right' />}
                  </TableRow>
                </TableHeader>
              }
              onRowClick={openDetail}
              renderRow={(payment) => (
                <TableRow
                  key={payment.id}
                  className='cursor-pointer'
                  onClick={() => openDetail(payment)}
                >
                  <TableCell className='font-mono text-[12.5px] text-[var(--ink-2)]'>
                    <span className='flex items-center gap-1.5'>
                      {payment.receipt_number ?? '—'}
                      {payment.earmark_type === EARMARK_TYPE.PROJECT_CONTRIBUTION && (
                        <StatusPill tone='b'>Proyecto</StatusPill>
                      )}
                    </span>
                  </TableCell>
                  <TableCell className='font-medium'>
                    {payment.unit?.full_identifier ?? payment.unit?.number ?? '—'}
                    {payment.payer_user?.name && (
                      <span className='block text-[11.5px] font-normal text-[var(--ink-3)]'>
                        {payment.payer_user.name}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className='text-right font-semibold tabular-nums'>
                    {displayMoney(payment.total_amount)}
                  </TableCell>
                  <TableCell>{payment.payment_method?.name ?? '—'}</TableCell>
                  <TableCell>{displayFormatDate(payment.paid_at, 'mediumDate')}</TableCell>
                  <TableCell>
                    <StatusPill tone={paymentStatusTone(payment.payment_status_id)}>
                      {payment.payment_status?.name ?? '—'}
                    </StatusPill>
                  </TableCell>
                  {canValidate && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      {renderActions(payment)}
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(payment) => (
                <span className='flex items-center gap-1.5'>
                  {payment.receipt_number ?? '—'}
                  {payment.earmark_type === EARMARK_TYPE.PROJECT_CONTRIBUTION && (
                    <StatusPill tone='b'>Proyecto</StatusPill>
                  )}
                </span>
              )}
              renderCardFields={(payment) => [
                {
                  label: 'Unidad',
                  value: (
                    <>
                      <span className='font-medium'>
                        {payment.unit?.full_identifier ?? payment.unit?.number ?? '—'}
                      </span>
                      {payment.payer_user?.name && (
                        <span className='block text-[11.5px] text-[var(--ink-3)]'>
                          {payment.payer_user.name}
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  label: 'Monto',
                  value: (
                    <span className='font-semibold tabular-nums'>
                      {displayMoney(payment.total_amount)}
                    </span>
                  ),
                },
                { label: 'Método', value: payment.payment_method?.name ?? '—' },
                { label: 'Fecha', value: displayFormatDate(payment.paid_at, 'mediumDate') },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={paymentStatusTone(payment.payment_status_id)}>
                      {payment.payment_status?.name ?? '—'}
                    </StatusPill>
                  ),
                },
              ]}
              renderActions={canValidate ? renderActions : undefined}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      {/* Único punto de captura: el sheet integra el modo "Cobro directo"
          (habilitado vía allowDirectCharge + permisos dentro del sheet). */}
      <RegisterPaymentSheet
        isOpen={registerOpen}
        onClose={() => setRegisterOpen(false)}
        allowDirectCharge
        onRegistered={refresh}
      />

      <PaymentActionDialogs dialog={dialog} />
    </div>
  )
}
