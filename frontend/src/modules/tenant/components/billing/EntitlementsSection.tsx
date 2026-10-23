import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import {
  BILLING_CURRENCY,
  billingIntervalLabel,
  isActiveEntitlement,
} from '@/modules/tenant/billing/billingFormat'
import type {
  IBillingAvailableModule,
  IBillingAvailablePlan,
  IBillingEntitlement,
  IEntitlementRequest,
  IPreferentialSwapRequest,
} from '@/modules/tenant/billing/schema'
import { PreferentialSwapDialog } from '@/modules/tenant/components/billing/PreferentialSwapDialog'
import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type { UseMutationResult } from '@tanstack/react-query'
import { AlertCircle, Clock, Gift, Sparkles, Undo2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { Panel } from '@/shared/components/molecules/Panel'

const SOURCE_LABELS: Record<string, string> = {
  super_admin_free: 'Cortesía gratuita',
  stripe: 'Pagado (Stripe)',
  manual: 'Pagado (manual)',
  trial: 'Prueba gratuita',
  legacy_plan: 'Plan legado',
}

/**
 * Texto de precio de un entitlement ACTIVO: cortesía → "Sin costo"; precio sin
 * configurar → aviso; el resto → precio del plan por intervalo (ej. "$950.00 / mes").
 */
function activePriceText(entitlement: IBillingEntitlement): string {
  if (entitlement.source === 'super_admin_free') {
    return 'Sin costo'
  }
  if (entitlement.amount === null) {
    return 'Precio no configurado'
  }
  return `${formatPrice(entitlement.amount, BILLING_CURRENCY)} / ${billingIntervalLabel(entitlement.interval)}`
}

/**
 * ¿Es una variante PREFERENCIAL (plan oculto)? El swap preferencial del backend
 * exige `is_public = false`: ofrecer un plan público en el desplegable garantiza un
 * 422, así que se filtran.
 *
 * Única señal: el `is_public` que el backend emite en `available_plans`. Aquí vivía
 * un respaldo que, cuando la clave no venía, adivinaba la visibilidad comparando
 * `plan.key` contra una lista de keys escrita a mano en el frontend. Se eliminó: esa
 * lista era una copia del catálogo del backend, y el schema ahora exige `is_public`
 * (lo emite siempre `PlanPricingService::buildPlanCatalog()`), así que no hay caso
 * en el que haya que adivinar.
 */
function isPreferentialPlan(plan: IBillingAvailablePlan): boolean {
  return plan.is_public === false
}

interface SwapDialogState {
  toKey: string
  toName: string
}

interface EntitlementsSectionProps {
  tenantId: string
  /** Plan de facturación actual del tenant, o null si no tiene ninguno. */
  plan: { key: string; name: string } | null
  availableModules: IBillingAvailableModule[]
  availablePlans: IBillingAvailablePlan[]
  entitlements: IBillingEntitlement[]
  grantFree: UseMutationResult<void, unknown, IEntitlementRequest>
  revoke: UseMutationResult<void, unknown, IEntitlementRequest>
  applyPreferentialSwap: UseMutationResult<void, unknown, IPreferentialSwapRequest>
  startTrial: UseMutationResult<void, unknown, IEntitlementRequest>
}

/**
 * Plan de la organización (billing v2, "todo es base"). Ya no se venden módulos por
 * separado, así que la lista por módulo colapsa a UNA tarjeta a nivel PLAN: qué plan
 * tiene contratado, de qué fuente (cortesía / Stripe / manual / prueba) y a qué
 * precio. Las acciones (activar gratis / activar prueba / revocar cortesía) se
 * conservan y operan sobre el plan; por debajo siguen viajando al backend con la key
 * del módulo base, que es el único módulo facturable que queda.
 */
export function EntitlementsSection({
  tenantId,
  plan,
  availableModules,
  availablePlans,
  entitlements,
  grantFree,
  revoke,
  applyPreferentialSwap,
  startTrial,
}: EntitlementsSectionProps) {
  const [swapState, setSwapState] = useState<SwapDialogState | null>(null)
  const [selectedSwapPlanKey, setSelectedSwapPlanKey] = useState<string>('')
  const [revokeOpen, setRevokeOpen] = useState(false)

  // Destinos válidos del swap: SÓLO variantes preferenciales (ocultas) distintas
  // de la actual. Un plan público siempre sería rechazado con 422 por el backend.
  const swappablePlans = useMemo(
    () => availablePlans.filter((p) => p.key !== plan?.key && isPreferentialPlan(p)),
    [availablePlans, plan]
  )

  // El módulo que representa al plan: el base. Si el backend aún no lo marca,
  // caemos al primero disponible para no dejar la sección sin acciones.
  const planModule = useMemo(
    () => availableModules.find((mod) => mod.is_base) ?? availableModules[0] ?? null,
    [availableModules]
  )

  // Entitlement ACTIVO que representa el plan de la organización (el más reciente).
  const activeEntitlement = useMemo(() => {
    if (!planModule) return null
    return (
      entitlements.find(
        (entitlement) =>
          entitlement.module_key === planModule.key && isActiveEntitlement(entitlement)
      ) ?? null
    )
  }, [entitlements, planModule])

  const isActive = Boolean(activeEntitlement)
  const isFree = activeEntitlement?.source === 'super_admin_free'
  const grantBusy = grantFree.isPending
  const revokeBusy = revoke.isPending
  const trialBusy = startTrial.isPending

  const planLabel = plan?.name ?? activeEntitlement?.plan_name ?? 'Sin plan asignado'

  /**
   * Error INLINE de la última acción sobre el plan. Los toasts pueden pasar
   * desapercibidos (el panel vive dentro de un diálogo modal), y sin esto un 422
   * — por ejemplo el guard de revocar — se ve como un botón que no hace nada.
   */
  const actionError = revoke.isError
    ? resolveBillingError(revoke.error, 'No se pudo revocar el acceso.')
    : grantFree.isError
      ? resolveBillingError(grantFree.error, 'No se pudo activar el plan sin costo.')
      : startTrial.isError
        ? resolveBillingError(startTrial.error, 'No se pudo activar la prueba.')
        : null

  /** Limpia el error inline anterior para que no se confunda con el de la acción nueva. */
  const resetActionErrors = () => {
    revoke.reset()
    grantFree.reset()
    startTrial.reset()
  }

  const handleConfirmRevoke = () => {
    if (!planModule) return
    resetActionErrors()
    revoke.mutate({ module_key: planModule.key }, { onSuccess: () => setRevokeOpen(false) })
  }

  const handleGrantFree = () => {
    if (!planModule) return
    resetActionErrors()
    grantFree.mutate({ module_key: planModule.key })
  }

  const handleStartTrial = () => {
    if (!planModule) return
    resetActionErrors()
    startTrial.mutate({ module_key: planModule.key })
  }

  const handleOpenSwap = () => {
    if (!selectedSwapPlanKey) return
    const targetPlan = swappablePlans.find((p) => p.key === selectedSwapPlanKey)
    if (!targetPlan) return
    setSwapState({ toKey: targetPlan.key, toName: targetPlan.name })
  }

  return (
    <Panel
      title='Plan de la organización'
      description='Todas las funciones de la plataforma van incluidas en el plan. Activar gratis otorga acceso permanente sin costo; revocar lo retira.'
    >
      {/* Plan actual + selector de cambio de plan */}
      <div className='flex flex-col gap-3 border border-border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between'>
        <div className='min-w-0 space-y-0.5'>
          <SectionLabel>Plan actual</SectionLabel>
          <p className='text-sm font-medium text-foreground'>
            {plan ? plan.name : <span className='text-muted-foreground'>Sin plan asignado</span>}
          </p>
        </div>

        {swappablePlans.length > 0 ? (
          <div className='flex shrink-0 flex-wrap items-center gap-2'>
            <Select value={selectedSwapPlanKey} onValueChange={setSelectedSwapPlanKey}>
              <SelectTrigger className='h-8 w-auto min-w-[160px] text-sm'>
                <SelectValue placeholder='Elige plan preferencial' />
              </SelectTrigger>
              <SelectContent>
                {swappablePlans.map((p) => (
                  <SelectItem key={p.key} value={p.key}>
                    {p.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              type='button'
              variant='outline'
              size='sm'
              disabled={!selectedSwapPlanKey || applyPreferentialSwap.isPending}
              onClick={handleOpenSwap}
            >
              <Sparkles className='h-4 w-4' aria-hidden />
              Cambiar a preferencial
            </Button>
          </div>
        ) : (
          <p className='shrink-0 text-xs text-muted-foreground sm:max-w-[260px] sm:text-right'>
            No hay planes preferenciales configurados. Crea una variante oculta en "Planes de
            facturación" para poder ofrecer un precio especial.
          </p>
        )}
      </div>

      {/* Estado del plan contratado (una sola tarjeta, ya no una lista por módulo) */}
      {planModule ? (
        <div className='mt-4 flex flex-col gap-3 border border-border p-4 sm:flex-row sm:items-start sm:justify-between'>
          <div className='min-w-0 space-y-1'>
            <div className='flex flex-wrap items-center gap-2'>
              <span className='text-sm font-medium text-foreground'>{planLabel}</span>
              {isActive ? (
                <StatusPill tone='b'>Activo</StatusPill>
              ) : (
                <StatusPill tone='n'>No activo</StatusPill>
              )}
            </div>
            {activeEntitlement ? (
              <p className='text-xs text-muted-foreground'>
                {SOURCE_LABELS[activeEntitlement.source] ?? activeEntitlement.source}
                {' · '}
                {activePriceText(activeEntitlement)}
                {activeEntitlement.expires_at
                  ? ` · vence el ${displayFormatDate(activeEntitlement.expires_at, 'mediumDate')}`
                  : ' · sin vencimiento'}
              </p>
            ) : (
              <p className='text-xs text-muted-foreground'>
                La organización no tiene el plan activo todavía.
              </p>
            )}
          </div>

          <div className='flex shrink-0 flex-wrap gap-2'>
            {isFree ? (
              <Button
                type='button'
                variant='outline'
                size='sm'
                disabled={revokeBusy}
                onClick={() => setRevokeOpen(true)}
              >
                <Undo2 className='h-4 w-4' aria-hidden />
                {revokeBusy ? 'Revocando…' : 'Revocar cortesía'}
              </Button>
            ) : (
              <>
                <Button
                  type='button'
                  variant='outline'
                  size='sm'
                  disabled={grantBusy}
                  onClick={handleGrantFree}
                >
                  <Gift className='h-4 w-4' aria-hidden />
                  {grantBusy ? 'Activando…' : 'Activar gratis'}
                </Button>
                {!isActive && (
                  <Button
                    type='button'
                    variant='outline'
                    size='sm'
                    disabled={trialBusy}
                    onClick={handleStartTrial}
                  >
                    <Clock className='h-4 w-4' aria-hidden />
                    {trialBusy ? 'Activando…' : 'Activar prueba del plan'}
                  </Button>
                )}
              </>
            )}
          </div>
        </div>
      ) : (
        <InlineEmpty className='mt-4'>No hay ningún plan facturable configurado en el catálogo.</InlineEmpty>
      )}

      {actionError && (
        <p
          role='alert'
          className='mt-4 flex items-start gap-2 bg-destructive/5 p-3 text-sm text-destructive'
        >
          <AlertCircle className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
          <span>{actionError}</span>
        </p>
      )}

      {swapState && (
        <PreferentialSwapDialog
          isOpen={Boolean(swapState)}
          tenantId={tenantId}
          toPlanKey={swapState.toKey}
          toPlanName={swapState.toName}
          applyPreferentialSwap={applyPreferentialSwap}
          onClose={() => {
            setSwapState(null)
            setSelectedSwapPlanKey('')
          }}
        />
      )}

      <ConfirmDialog
        isOpen={revokeOpen}
        variant='default'
        title='Revocar cortesía'
        description={`Vas a retirar el acceso gratuito al plan "${planLabel}". La organización dejará de tener acceso a la plataforma. ¿Deseas continuar?`}
        confirmLabel='Revocar'
        isLoading={revoke.isPending}
        onConfirm={handleConfirmRevoke}
        onCancel={() => setRevokeOpen(false)}
      />
    </Panel>
  )
}
