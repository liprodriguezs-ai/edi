import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import type {
  IMultiItemSubscription,
  IStripeSubscription,
} from '@/modules/checkoutSubscription/schema'
import { intervalSuffix } from '@/modules/user/components/subscriptionHelpers'
import {
  CancellationBanner,
  SubscriptionDates,
  SubscriptionStatusBadge,
} from '@/modules/user/components/subscriptionPresentation'
import { ExternalLink, XCircle } from 'lucide-react'

interface MultiItemSubscriptionCardProps {
  multiItem: IMultiItemSubscription
  subscription: IStripeSubscription | null
  status: string | null
  onPortal: () => void
  onCancel: () => void
  onResume: () => void
  isResuming: boolean
  /**
   * ¿Puede el usuario MODIFICAR la facturación? Sólo el dueño de la cuenta: el
   * backend responde 403 `not_account_owner` en portal/cancel/resume. Un usuario
   * con `manage.subscription` que no es dueño ve la tarjeta sin acciones.
   */
  canManage: boolean
}

/**
 * Vista de la suscripción del organización: el PLAN contratado con su precio recurrente,
 * estado, fechas del ciclo y acciones de ciclo de vida (portal / cancelar /
 * reactivar). Ya no hay desglose por módulo: todo va incluido en el plan.
 */
export function MultiItemSubscriptionCard({
  multiItem,
  subscription,
  status,
  onPortal,
  onCancel,
  onResume,
  isResuming,
  canManage,
}: MultiItemSubscriptionCardProps) {
  const { currency, interval, total, plan } = multiItem
  const cancelScheduled = subscription?.cancel_at_period_end ?? false

  return (
    <Card>
      <CardHeader>
        <div className='flex flex-wrap items-start justify-between gap-4'>
          <div>
            <CardTitle className='text-lg'>Plan actual</CardTitle>
            <CardDescription>
              {plan ? `Plan ${plan.name}` : 'Suscripción de tu organización'}
            </CardDescription>
          </div>
          <SubscriptionStatusBadge status={status} />
        </div>
      </CardHeader>
      <CardContent className='space-y-6'>
        <div className='flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between'>
          <div className='space-y-1'>
            <p className='text-sm font-medium text-foreground'>
              {plan ? plan.name : 'Plan contratado'}
            </p>
            <p className='text-sm text-muted-foreground'>
              Todas las funciones de la plataforma están incluidas.
            </p>
          </div>
          <div className='text-right'>
            <p className='text-3xl font-bold tracking-tight'>{formatPrice(total, currency)}</p>
            <p className='text-sm text-muted-foreground'>{intervalSuffix(interval)}</p>
          </div>
        </div>

        {subscription && (
          <>
            <CancellationBanner
              subscription={subscription}
              onResume={onResume}
              isResuming={isResuming}
              canResume={canManage}
            />
            <SubscriptionDates subscription={subscription} />
          </>
        )}

        {canManage && (
          <div className='flex flex-wrap items-center gap-3 border-t pt-6'>
            <Button variant='outline' onClick={onPortal}>
              <ExternalLink className='mr-2 h-4 w-4' />
              Portal de facturación
            </Button>
            {!cancelScheduled && (
              <Button
                variant='ghost'
                className='text-destructive hover:text-destructive'
                onClick={onCancel}
              >
                <XCircle className='mr-2 h-4 w-4' />
                Cancelar suscripción
              </Button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
