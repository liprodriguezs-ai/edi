import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type {
  IMultiItemSubscription,
  IStripeSubscription,
} from '@/modules/checkoutSubscription/schema'
import { ChangeBillingPlanModal } from '@/modules/user/components/ChangeBillingPlanModal'
import { ChangeIntervalModal } from '@/modules/user/components/ChangeIntervalModal'
import { MultiItemSubscriptionCard } from '@/modules/user/components/MultiItemSubscriptionCard'
import { ArrowLeftRight, Repeat } from 'lucide-react'
import { useState } from 'react'

interface MultiItemSubscriptionManagerProps {
  multiItem: IMultiItemSubscription
  subscription: IStripeSubscription | null
  status: string | null
  onPortal: () => void
  onCancel: () => void
  onResume: () => void
  isResuming: boolean
  /** Refresca la pantalla tras aplicar un cambio (recarga silenciosa + sesión). */
  onApplied: () => void | Promise<void>
  /** Unidades activas de la organización: es lo que decide qué banda de plan le toca. */
  unitCount: number
  /**
   * ¿Puede el usuario MODIFICAR la facturación? Sólo el dueño de la cuenta. Con
   * `false` la barra de gestión no se monta: el backend responde 403
   * `not_account_owner` en change-billing-plan, y el cambio de intervalo pasa por
   * la misma suscripción, así que ofrecerlos sería prometer algo que falla.
   */
  canManage: boolean
}

/**
 * Orquesta la gestión auto-servicio de la suscripción del dueño: la tarjeta del plan
 * contratado + la barra de gestión (cambiar intervalo / cambiar plan) y es dueña del
 * estado de esos modales. Cada modal ejecuta su propio flujo PREVIEW → CONFIRMAR y se
 * monta condicionalmente, así el catálogo de planes carga LAZY (sólo al abrir).
 *
 * Ya no se agregan ni se quitan módulos: todo lo que ofrece la plataforma viene dentro
 * del plan, y el precio depende sólo de la banda de unidades. Cambiar de PLAN
 * (basico↔premium) se hace con "Cambiar plan": el backend aplica el prorrateo al acto
 * sin necesidad de un re-checkout.
 */
export function MultiItemSubscriptionManager({
  multiItem,
  subscription,
  status,
  onPortal,
  onCancel,
  onResume,
  isResuming,
  onApplied,
  unitCount,
  canManage,
}: MultiItemSubscriptionManagerProps) {
  const [intervalOpen, setIntervalOpen] = useState(false)
  const [changePlanOpen, setChangePlanOpen] = useState(false)

  const planKey = multiItem.plan?.key ?? null

  // Con una cancelación programada, cambiar el plan es confuso: primero hay que
  // reactivar. Deshabilitamos la barra de gestión completa en ese estado.
  const cancelScheduled = subscription?.cancel_at_period_end ?? false

  return (
    <>
      <MultiItemSubscriptionCard
        multiItem={multiItem}
        subscription={subscription}
        status={status}
        onPortal={onPortal}
        onCancel={onCancel}
        onResume={onResume}
        isResuming={isResuming}
        canManage={canManage}
      />

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle className='text-lg'>Gestionar suscripción</CardTitle>
            <CardDescription>
              Cambia tu plan o el intervalo de cobro. Verás el prorrateo antes de confirmar
              cualquier cambio.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {cancelScheduled ? (
              <p className='border bg-muted/40 p-4 text-sm text-muted-foreground'>
                Tu suscripción tiene una cancelación programada. Reactívala para volver a gestionar
                tu plan.
              </p>
            ) : (
              <div className='flex flex-wrap gap-3'>
                <Button variant='outline' onClick={() => setIntervalOpen(true)}>
                  <Repeat className='mr-2 h-4 w-4' />
                  Cambiar intervalo
                </Button>
                <Button
                  variant='outline'
                  onClick={() => setChangePlanOpen(true)}
                  disabled={!planKey}
                >
                  <ArrowLeftRight className='mr-2 h-4 w-4' />
                  Cambiar plan
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {intervalOpen && (
        <ChangeIntervalModal
          isOpen
          currentInterval={multiItem.interval}
          onClose={() => setIntervalOpen(false)}
          onApplied={onApplied}
        />
      )}

      {changePlanOpen && planKey && (
        <ChangeBillingPlanModal
          isOpen
          currentPlanKey={planKey}
          currentInterval={multiItem.interval}
          unitCount={unitCount}
          onClose={() => setChangePlanOpen(false)}
          onApplied={onApplied}
        />
      )}
    </>
  )
}
