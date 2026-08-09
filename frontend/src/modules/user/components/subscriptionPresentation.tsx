import { Button } from '@/components/ui/button'
import type { IStripeSubscription } from '@/modules/checkoutSubscription/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { AlertTriangle, Calendar, Loader2, RotateCcw, ShieldAlert } from 'lucide-react'

/**
 * Presentación COMPARTIDA de la suscripción, reutilizada por la vista de plan
 * legado (`SubscriptionPage`) y por la vista multi-item (`MultiItemSubscriptionCard`).
 * Vive aquí para no duplicar el badge de estado, el aviso de cancelación ni las
 * fechas del ciclo entre ambas vistas.
 */

const STATUS_LABELS: Record<string, { label: string; className: string }> = {
  active: {
    label: 'Activa',
    className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  },
  trialing: {
    label: 'Periodo de prueba',
    className: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  },
  past_due: {
    label: 'Pago pendiente',
    className: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
  },
  canceled: {
    label: 'Cancelada',
    className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  },
  incomplete: {
    label: 'Incompleta',
    className: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
  },
  incomplete_expired: {
    label: 'Expirada',
    className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  },
  unpaid: {
    label: 'Sin pagar',
    className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  },
}

export function SubscriptionStatusBadge({ status }: { status?: string | null }) {
  if (!status) return null
  const info = STATUS_LABELS[status]
  if (!info) return null
  return (
    <span
      className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ${info.className}`}
    >
      {info.label}
    </span>
  )
}

interface CancellationBannerProps {
  subscription: IStripeSubscription
  onResume: () => void
  isResuming: boolean
  /**
   * ¿Puede el usuario reactivar? `POST /config/subscription/resume` responde 403
   * `not_account_owner` a quien no es dueño de la cuenta, así que el botón se
   * oculta en ese caso; el aviso informativo sí se muestra.
   */
  canResume: boolean
}

export function CancellationBanner({
  subscription,
  onResume,
  isResuming,
  canResume,
}: CancellationBannerProps) {
  if (!subscription.cancel_at_period_end) return null
  const endDate = subscription.ends_at ?? subscription.current_period_end
  return (
    <div className='flex items-start gap-3 rounded-lg border border-yellow-500/30 bg-yellow-50 p-4 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-200'>
      <AlertTriangle className='mt-0.5 h-5 w-5 shrink-0' />
      <div className='flex-1'>
        <p className='font-semibold'>Cancelación programada</p>
        <p className='mt-0.5 text-sm'>
          Tu plan se cancelará el{' '}
          {endDate ? displayFormatDate(endDate, 'mediumDate') : 'la fecha de fin del período'}.
          Mantendrás acceso hasta esa fecha.
        </p>
        {canResume && (
          <Button
            size='sm'
            className='mt-3 bg-yellow-600 text-white hover:bg-yellow-700'
            disabled={isResuming}
            onClick={onResume}
          >
            {isResuming ? (
              <>
                <Loader2 className='mr-2 h-4 w-4 animate-spin' />
                Reactivando...
              </>
            ) : (
              <>
                <RotateCcw className='mr-2 h-4 w-4' />
                Reactivar suscripción
              </>
            )}
          </Button>
        )}
      </div>
    </div>
  )
}

export function SubscriptionDates({ subscription }: { subscription: IStripeSubscription }) {
  // No upcoming charge once a cancellation is scheduled — the banner already shows
  // the access-until date, so showing "Próximo cobro" here would be contradictory.
  const showNextCharge = subscription.current_period_end && !subscription.cancel_at_period_end
  return (
    <div className='grid gap-4 sm:grid-cols-2 lg:grid-cols-3'>
      {showNextCharge && (
        <div className='flex items-start gap-3 rounded-lg border p-4'>
          <Calendar className='mt-0.5 h-5 w-5 shrink-0 text-muted-foreground' />
          <div>
            <p className='text-xs font-medium text-muted-foreground'>Próximo cobro</p>
            <p className='text-sm font-semibold'>
              {displayFormatDate(subscription.current_period_end, 'mediumDate')}
            </p>
          </div>
        </div>
      )}
      {subscription.trial_ends_at && (
        <div className='flex items-start gap-3 rounded-lg border p-4'>
          <ShieldAlert className='mt-0.5 h-5 w-5 shrink-0 text-muted-foreground' />
          <div>
            <p className='text-xs font-medium text-muted-foreground'>Fin del periodo de prueba</p>
            <p className='text-sm font-semibold'>
              {displayFormatDate(subscription.trial_ends_at, 'mediumDate')}
            </p>
          </div>
        </div>
      )}
      {subscription.current_period_start && (
        <div className='flex items-start gap-3 rounded-lg border p-4'>
          <Calendar className='mt-0.5 h-5 w-5 shrink-0 text-muted-foreground' />
          <div>
            <p className='text-xs font-medium text-muted-foreground'>Inicio del periodo</p>
            <p className='text-sm font-semibold'>
              {displayFormatDate(subscription.current_period_start, 'mediumDate')}
            </p>
          </div>
        </div>
      )}
    </div>
  )
}
