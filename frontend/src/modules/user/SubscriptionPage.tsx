import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { CancelSubscriptionModal } from '@/modules/user/components/CancelSubscriptionModal'
import { MultiItemSubscriptionManager } from '@/modules/user/components/MultiItemSubscriptionManager'
import { TrialExpiryBanner } from '@/modules/user/components/TrialExpiryBanner'
import { useSubscription, type SubscriptionDetails } from '@/modules/user/hooks/useSubscription'
import { Callout } from '@/shared/components/atoms/Callout'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { AlertCircle, CreditCard, Info, Loader2, Lock } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'

/**
 * Cómo se explica cada fuente de acceso que NO pasa por Stripe. La clave es el
 * `source` del entitlement; una fuente desconocida cae al texto genérico.
 */
const PLAN_SOURCE_COPY: Record<string, { badge: string; description: string }> = {
  manual: {
    badge: 'Pago fuera de línea',
    description:
      'Tu plan está activo y se cobra por transferencia o efectivo. El equipo de Kivo7 registra cada pago; no necesitas pagar con tarjeta aquí.',
  },
  super_admin_free: {
    badge: 'Cortesía',
    description:
      'Tu condominio tiene acceso de cortesía, sin costo y sin vencimiento. No hay nada que pagar.',
  },
  trial: {
    badge: 'Prueba',
    description:
      'Estás usando el plan en período de prueba. Cuando termine tendrás que contratarlo para conservar el acceso.',
  },
  legacy_plan: {
    badge: 'Plan asignado',
    description: 'Tu plan fue asignado por el equipo de Kivo7 y está activo.',
  },
}

const GENERIC_PLAN_SOURCE = {
  badge: 'Plan activo',
  description:
    'Tu plan está activo y no se cobra con tarjeta desde esta pantalla. Si necesitas cambiarlo, contacta al equipo de Kivo7.',
}

/**
 * Plan vigente que NO viene de una suscripción de Stripe (pago manual, cortesía,
 * prueba). No hay autogestión: se informa el plan, su fuente y su vencimiento, y NO
 * se ofrece contratar por tarjeta como si el clúster no tuviera nada.
 */
function NonStripePlanCard({
  plan,
  trials,
  canContract,
}: {
  plan: NonNullable<SubscriptionDetails['currentPlan']>
  trials: SubscriptionDetails['trials']
  canContract: boolean
}) {
  const copy = (plan.source && PLAN_SOURCE_COPY[plan.source]) || GENERIC_PLAN_SOURCE

  return (
    <>
      <TrialExpiryBanner trials={trials} canContract={canContract} />
      <Card>
        <CardContent className='space-y-3 py-8'>
          <div className='flex flex-wrap items-center gap-2'>
            <h3 className='text-lg font-semibold'>Plan {plan.name}</h3>
            <Badge variant='secondary'>{copy.badge}</Badge>
          </div>
          <p className='text-sm text-muted-foreground'>{copy.description}</p>
          {plan.expires_at && (
            <p className='text-sm text-muted-foreground'>
              Acceso vigente hasta el{' '}
              <span className='font-medium text-foreground'>
                {displayFormatDate(plan.expires_at, 'mediumDate')}
              </span>
              .
            </p>
          )}
        </CardContent>
      </Card>
    </>
  )
}

/**
 * Nota para quien PUEDE ver la facturación pero no modificarla. El backend responde
 * 403 `not_account_owner` en portal/cancel/resume/change-billing-plan, así que en vez
 * de mostrar botones que fallan, se explica quién sí puede hacerlo.
 */
function ReadOnlyBillingNote() {
  return (
    <Callout icon={Info}>
      Puedes consultar la suscripción del condominio, pero sólo el propietario de la cuenta puede
      modificar la facturación (cambiar de plan o de intervalo, abrir el portal de pago y cancelar o
      reactivar).
    </Callout>
  )
}

export function SubscriptionPage() {
  // Modificar la facturación es exclusivo del dueño de la cuenta (gate del backend).
  // VER la suscripción está abierto a quien tenga `manage.subscription` — un tesorero
  // debe poder consultar qué paga el clúster sin poder tocarlo.
  const isOwner = useRoles(ERoleUserSlug.ACCOUNT_OWNER) === true
  const canViewBilling = usePermissions('manage.subscription')
  const navigate = useNavigate()
  const {
    details,
    isLoading,
    hasLoadError,
    isCanceling,
    isResuming,
    handleCancel,
    handleResume,
    handlePortal,
    reload,
    refresh,
  } = useSubscription()

  const [cancelModalOpen, setCancelModalOpen] = useState(false)

  const headerSubtitle = isOwner
    ? 'Gestiona tu plan y facturación'
    : 'Consulta el plan y la facturación del condominio'

  const handleCancelConfirm = useCallback(async () => {
    const ok = await handleCancel()
    if (ok) setCancelModalOpen(false)
  }, [handleCancel])

  if (!isOwner && !canViewBilling) {
    return (
      <div className='flex flex-col items-center justify-center py-20 text-center'>
        <div className='mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-muted'>
          <Lock className='h-8 w-8 text-muted-foreground' />
        </div>
        <h2 className='text-xl font-semibold'>Acceso restringido</h2>
        <p className='mt-2 max-w-md text-muted-foreground'>
          No tienes permiso para consultar la suscripción del condominio. Contacta al administrador de
          tu organización.
        </p>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className='flex items-center justify-center py-20'>
        <Loader2 className='h-8 w-8 animate-spin text-primary' />
      </div>
    )
  }

  if (hasLoadError) {
    return (
      <div className='space-y-6'>
        <div>
          <h1 className='text-2xl font-bold tracking-tight'>Mi suscripción</h1>
          <p className='text-muted-foreground'>{headerSubtitle}</p>
        </div>
        <Card>
          <CardContent className='flex flex-col items-center justify-center py-12 text-center'>
            <AlertCircle className='mb-4 h-10 w-10 text-destructive' />
            <h3 className='text-lg font-semibold'>No se pudo cargar la información</h3>
            <p className='mt-1 mb-6 text-sm text-muted-foreground'>
              Ocurrió un error al obtener los datos de tu suscripción. Intenta de nuevo.
            </p>
            <Button onClick={() => void reload()}>Reintentar</Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const subscription = details?.subscription ?? null
  const multiItem = details?.multiItem ?? null
  const currentPlan = details?.currentPlan ?? null

  // Sin suscripción de Stripe no hay autogestión posible, PERO el clúster igual
  // puede tener plan por pago manual, cortesía o prueba: en ese caso se muestra el
  // plan y su fuente, sin invitar a contratar de nuevo con tarjeta.
  if (!multiItem) {
    return (
      <div className='space-y-6'>
        <div>
          <h1 className='text-2xl font-bold tracking-tight'>Mi suscripción</h1>
          <p className='text-muted-foreground'>{headerSubtitle}</p>
        </div>
        {!isOwner && <ReadOnlyBillingNote />}
        {currentPlan ? (
          <NonStripePlanCard
            plan={currentPlan}
            trials={details?.trials ?? []}
            canContract={isOwner}
          />
        ) : (
          <Card>
            <CardContent className='flex flex-col items-center justify-center py-12 text-center'>
              <CreditCard className='mb-4 h-10 w-10 text-muted-foreground' />
              <h3 className='text-lg font-semibold'>Sin plan activo</h3>
              <p className='mt-1 mb-6 text-sm text-muted-foreground'>
                {isOwner
                  ? 'Aún no tienes un plan asignado. Selecciona uno para comenzar.'
                  : 'El condominio aún no tiene un plan asignado.'}
              </p>
              {isOwner && (
                <Button onClick={() => navigate('/select-plan')}>Seleccionar un plan</Button>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    )
  }

  const cancelPlanName =
    details?.plan?.name ??
    multiItem.plan?.name ??
    multiItem.modules.find((module) => module.is_base)?.name ??
    'tu suscripción'

  return (
    <div className='space-y-6'>
      <div>
        <h1 className='text-2xl font-bold tracking-tight'>Mi suscripción</h1>
        <p className='text-muted-foreground'>{headerSubtitle}</p>
      </div>

      {!isOwner && <ReadOnlyBillingNote />}

      <TrialExpiryBanner trials={details?.trials ?? []} canContract={isOwner} />

      <MultiItemSubscriptionManager
        multiItem={multiItem}
        subscription={subscription}
        status={subscription?.stripe_status ?? null}
        onPortal={() => {
          void handlePortal()
        }}
        onCancel={() => setCancelModalOpen(true)}
        onResume={() => {
          void handleResume()
        }}
        isResuming={isResuming}
        onApplied={refresh}
        unitCount={details?.unitCount ?? 0}
        canManage={isOwner}
      />

      <CancelSubscriptionModal
        isOpen={cancelModalOpen}
        isCanceling={isCanceling}
        onCancel={handleCancelConfirm}
        onClose={() => setCancelModalOpen(false)}
        planName={cancelPlanName}
      />
    </div>
  )
}
