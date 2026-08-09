import { Button } from '@/components/ui/button'
import { formatChargePeriod } from '@/modules/charge/schema'
import { PaymentActionButtons } from '@/modules/payment/components/PaymentActionButtons'
import { PaymentActionDialogs } from '@/modules/payment/components/PaymentActionDialogs'
import { usePaymentActions } from '@/modules/payment/hooks/usePaymentActions'
import {
  PAYMENT_STATUS,
  paymentStatusTone,
  type IPayment,
  type IPaymentApplication,
} from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { isNotFoundError } from '@/shared/api/apiClient'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import type { IMedia } from '@/shared/interfaces/Entity'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'
import { Skeleton } from 'boneyard-js/react'
import { ArrowLeft, Download, FileText } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

/**
 * Relaciones que necesita el detalle. El residente alcanza esta página por el
 * deep-link de las notificaciones de cobranza; el backend las whitelistea en
 * PaymentController::includes() y autoriza el show de pagos de la unidad propia.
 */
const PAYMENT_DETAIL_INCLUDE = [
  'unit',
  'unit.unitType',
  'unit.tower',
  'payment_status',
  'payment_method',
  'bank_account',
  'applications',
  'applications.charge',
  'applications.charge.concept',
  'applications.charge.batch',
  'payer_user',
  'media',
].join(',')

function mediaOf(payment: IPayment, collection: string): IMedia | undefined {
  return payment.media?.find((m) => m.collection_name === collection)
}

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

interface ApplicationsCardProps {
  applications: IPaymentApplication[]
}

/** Aplicaciones FIFO: a qué cargos se aplicó el pago y cuánto a cada uno. */
function ApplicationsCard({ applications }: ApplicationsCardProps) {
  return (
    <section className='overflow-hidden rounded-[16px] border border-border bg-card shadow-sm'>
      <div className='border-b border-border bg-[var(--surface-2)] px-4 py-3'>
        <h2 className='text-[13px] font-semibold tracking-[-0.01em]'>Aplicación a cargos (FIFO)</h2>
      </div>
      {applications.length === 0 ? (
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>
          Este pago aún no se aplicó a ningún cargo.
        </p>
      ) : (
        <ul role='list'>
          {applications.map((application) => {
            const charge = application.charge
            return (
              <li
                key={application.id}
                className='flex items-center justify-between gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
              >
                <div className='min-w-0'>
                  <div className='truncate font-medium text-foreground'>
                    {charge?.concept?.name ?? 'Cargo'}
                  </div>
                  {charge && (
                    <div className='text-[12.5px] text-[var(--ink-3)]'>
                      {formatChargePeriod(charge)}
                    </div>
                  )}
                </div>
                <div className='shrink-0 font-semibold tabular-nums'>
                  {displayMoney(application.applied_amount)}
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}

interface DownloadsCardProps {
  payment: IPayment
}

/** Comprobante subido por el residente (receipt) y comprobante del sistema (receipt_pdf). */
function DownloadsCard({ payment }: DownloadsCardProps) {
  const [downloadingId, setDownloadingId] = useState<string | null>(null)
  const receipt = mediaOf(payment, 'receipt')
  const receiptPdf = mediaOf(payment, 'receipt_pdf')

  if (!receipt && !receiptPdf) {
    return null
  }

  const download = async (media: IMedia) => {
    setDownloadingId(media.id)
    try {
      await downloadMediaWithSignedUrl({
        mediaId: media.id,
        fileName: media.file_name,
        errorMessage: 'No se pudo descargar el comprobante',
      })
    } finally {
      setDownloadingId(null)
    }
  }

  return (
    <section className='rounded-[16px] border border-border bg-card p-4 shadow-sm'>
      <h2 className='mb-3 text-[13px] font-semibold tracking-[-0.01em]'>Comprobantes</h2>
      <div className='flex flex-wrap gap-2'>
        {receiptPdf && (
          <Button
            variant='outline'
            className='rounded-full'
            onClick={() => void download(receiptPdf)}
            disabled={downloadingId === receiptPdf.id}
          >
            <FileText className='size-4' />
            Recibo del sistema
          </Button>
        )}
        {receipt && (
          <Button
            variant='outline'
            className='rounded-full'
            onClick={() => void download(receipt)}
            disabled={downloadingId === receipt.id}
          >
            <Download className='size-4' />
            Comprobante subido
          </Button>
        )}
      </div>
    </section>
  )
}

// ─── main component ──────────────────────────────────────────────────────────

/**
 * Detalle de un pago (§8.5): destino de los deep-links de notificaciones de
 * cobranza (payment_accepted / payment_registered_direct). Muestra folio, monto,
 * método, fecha, estado, unidad, cuenta destino y la aplicación FIFO a cargos,
 * con descargas de comprobantes.
 */
export const PaymentDetailPage = () => {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const {
    data: payment,
    isLoading,
    isError,
    error,
    refetch,
  } = useQuery({
    queryKey: ['payment', id],
    queryFn: () => PaymentResource.show(id as string, { include: PAYMENT_DETAIL_INCLUDE }),
    enabled: Boolean(id),
  })

  // Mismo gating de permiso que la lista (allí es `canValidate`).
  const canManage = usePermissions('update.payment')
  // Tras validar/rechazar/cancelar se refetchea el detalle: validar asigna
  // receipt_number y todas cambian el estado, así que la vista debe reflejarlo.
  const actions = usePaymentActions({ onActionSuccess: () => void refetch() })

  const notFound = isNotFoundError(error)

  useEffect(() => {
    if (isError && !notFound) {
      toast.error('No se pudo cargar el detalle del pago')
    }
  }, [isError, notFound])

  if (isError) {
    return (
      <div className='space-y-5'>
        <Button variant='ghost' className='-ml-2 gap-1.5' onClick={() => navigate('/payment')}>
          <ArrowLeft className='size-4' />
          Volver a pagos
        </Button>
        <EntityNotFound
          variant={notFound ? 'notFound' : 'error'}
          title={notFound ? 'Pago no encontrado' : 'No se pudo cargar el pago'}
          description={
            notFound
              ? 'El pago no existe o no tienes acceso a él.'
              : 'Ocurrió un error al obtener el detalle. Intenta de nuevo.'
          }
          onRetry={notFound ? undefined : () => void refetch()}
        />
      </div>
    )
  }

  const unitLabel = payment?.unit?.full_identifier ?? payment?.unit?.number ?? '—'

  return (
    <div className='space-y-5'>
      {/* Header */}
      <div className='flex flex-wrap items-start justify-between gap-3'>
        <div>
          <Button
            variant='ghost'
            className='-ml-2 mb-2 gap-1.5'
            onClick={() => navigate('/payment')}
            aria-label='Volver a pagos'
          >
            <ArrowLeft className='size-4' />
            Volver a pagos
          </Button>
          <div className='mb-[7px] text-[10.5px] font-semibold uppercase tracking-[0.15em] text-[var(--ink-4)]'>
            Cobranza
          </div>
          <Skeleton name='payment-detail-header' loading={isLoading}>
            <h1 className='font-mono text-[26px] font-semibold leading-[1.12] tracking-[-0.02em]'>
              {payment?.receipt_number ?? 'Pago pendiente'}
            </h1>
            {payment?.physical_receipt_number && (
              <div className='mt-1 text-[13px] text-[var(--ink-3)]'>
                Folio físico: <span className='font-mono'>{payment.physical_receipt_number}</span>
              </div>
            )}
          </Skeleton>
        </div>
        <div className='flex flex-col items-start gap-3 sm:items-end'>
          {payment?.payment_status && (
            <StatusPill tone={paymentStatusTone(payment.payment_status_id)}>
              {payment.payment_status.name}
            </StatusPill>
          )}
          {/* Acciones del pago — mismas que la lista, gateadas por permiso y por
              el estado REAL del pago (no hay pestaña en el detalle). La descarga
              de comprobantes vive en DownloadsCard, así que no se duplica aquí. */}
          {canManage && payment && (
            <PaymentActionButtons
              payment={payment}
              size='default'
              show={{
                download: false,
                validate: payment.payment_status_id === PAYMENT_STATUS.PENDING_VALIDATION,
                reject: payment.payment_status_id === PAYMENT_STATUS.PENDING_VALIDATION,
                cancel: payment.payment_status_id !== PAYMENT_STATUS.CANCELLED,
              }}
              downloadingId={actions.downloadingId}
              validatePending={actions.validatePending}
              rejectPending={actions.rejectPending}
              cancelPending={actions.cancelPending}
              onDownload={() => actions.downloadReceipt(payment)}
              onReject={() => actions.requestReject(payment)}
              onValidate={() => actions.requestValidate(payment)}
              onCancel={() => actions.requestCancel(payment)}
            />
          )}
        </div>
      </div>

      <Skeleton name='payment-detail-body' loading={isLoading}>
        {payment && (
          <div className='space-y-5'>
            {/* Resumen principal */}
            <section className='rounded-[16px] border border-border bg-card p-5 shadow-sm'>
              <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                Monto total
              </div>
              <div className='mt-1 text-[30px] font-semibold tabular-nums'>
                {displayMoney(payment.total_amount)}
              </div>

              <dl className='mt-5 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3'>
                <DetailField label='Método de pago'>
                  {payment.payment_method?.name ?? '—'}
                </DetailField>
                <DetailField label='Fecha de pago'>
                  {displayFormatDate(payment.paid_at, 'mediumDate') || '—'}
                </DetailField>
                <DetailField label='Unidad'>{unitLabel}</DetailField>
                <DetailField label='Cuenta destino'>
                  {payment.bank_account?.name ?? '—'}
                </DetailField>
                <DetailField label='Registrado por'>{payment.payer_user?.name ?? '—'}</DetailField>
                {payment.reference && (
                  <DetailField label='Referencia'>{payment.reference}</DetailField>
                )}
              </dl>

              {payment.notes && (
                <div className='mt-5 border-t border-[var(--line-2)] pt-4'>
                  <DetailField label='Notas'>{payment.notes}</DetailField>
                </div>
              )}

              {payment.cancellation_reason && (
                <div className='mt-4 rounded-[12px] border border-[#F0D6CE] bg-[#FBEEE9] px-4 py-3'>
                  <div className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[#C0533C]'>
                    Motivo de cancelación
                  </div>
                  <p className='mt-1 text-[13px] text-foreground'>{payment.cancellation_reason}</p>
                </div>
              )}
            </section>

            <ApplicationsCard applications={payment.applications ?? []} />

            <DownloadsCard payment={payment} />
          </div>
        )}
      </Skeleton>

      <PaymentActionDialogs dialog={actions.dialog} />
    </div>
  )
}
