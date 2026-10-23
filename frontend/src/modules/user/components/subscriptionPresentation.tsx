import { Button } from '@/components/ui/button'
import type { IStripeSubscription } from '@/modules/checkoutSubscription/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import type { PillTone } from '@/shared/components/atoms/StatusPill'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { AlertTriangle, Calendar, RotateCcw, ShieldAlert } from 'lucide-react'

/**
 * Presentación COMPARTIDA de la suscripción, reutilizada por la vista de plan
 * legado (`SubscriptionPage`) y por la vista multi-item (`MultiItemSubscriptionCard`).
 * Vive aquí para no duplicar el badge de estado, el aviso de cancelación ni las
 * fechas del ciclo entre ambas vistas.
 */

/** Stripe subscription status. Non-clinical tones: warning/danger only for billing problems. */
const STATUS_LABELS: Record<string, { label: string; tone: PillTone }> = {
  active: { label: 'Activa', tone: 'b' },
  trialing: { label: 'Periodo de prueba', tone: 'n' },
  past_due: { label: 'Pago pendiente', tone: 'y' },
  canceled: { label: 'Cancelada', tone: 'r' },
  incomplete: { label: 'Incompleta', tone: 'n' },
  incomplete_expired: { label: 'Expirada', tone: 'r' },
  unpaid: { label: 'Sin pagar', tone: 'r' },
}

export function SubscriptionStatusBadge({ status }: { status?: string | null }) {
  if (!status) return null
  const info = STATUS_LABELS[status]
  if (!info) return null
  return <StatusPill tone={info.tone}>{info.label}</StatusPill>
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
    <div className='flex items-start gap-3 border border-border bg-secondary p-4 text-foreground'>
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
            className='mt-3'
            loading={isResuming}
            onClick={onResume}
          >
            {isResuming ? (
              'Reactivando...'
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
        <div className='flex items-start gap-3 border p-4'>
          <Calendar className='mt-0.5 h-5 w-5 shrink-0 text-muted-foreground' />
          <div>
            <p className='text-xs font-medium text-muted-foreground'>Próximo cobro</p>
            <p className='font-mono text-sm font-semibold tabular-nums'>
              {displayFormatDate(subscription.current_period_end, 'mediumDate')}
            </p>
          </div>
        </div>
      )}
      {subscription.trial_ends_at && (
        <div className='flex items-start gap-3 border p-4'>
          <ShieldAlert className='mt-0.5 h-5 w-5 shrink-0 text-muted-foreground' />
          <div>
            <p className='text-xs font-medium text-muted-foreground'>Fin del periodo de prueba</p>
            <p className='font-mono text-sm font-semibold tabular-nums'>
              {displayFormatDate(subscription.trial_ends_at, 'mediumDate')}
            </p>
          </div>
        </div>
      )}
      {subscription.current_period_start && (
        <div className='flex items-start gap-3 border p-4'>
          <Calendar className='mt-0.5 h-5 w-5 shrink-0 text-muted-foreground' />
          <div>
            <p className='text-xs font-medium text-muted-foreground'>Inicio del periodo</p>
            <p className='font-mono text-sm font-semibold tabular-nums'>
              {displayFormatDate(subscription.current_period_start, 'mediumDate')}
            </p>
          </div>
        </div>
      )}
    </div>
  )
}
