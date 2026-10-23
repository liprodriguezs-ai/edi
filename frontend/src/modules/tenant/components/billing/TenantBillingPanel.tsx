import { Button } from '@/components/ui/button'
import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import { isActiveEntitlement } from '@/modules/tenant/billing/billingFormat'
import { EntitlementsSection } from '@/modules/tenant/components/billing/EntitlementsSection'
import { ExpirationSection } from '@/modules/tenant/components/billing/ExpirationSection'
import { LedgerSection } from '@/modules/tenant/components/billing/LedgerSection'
import { ManualPaymentSection } from '@/modules/tenant/components/billing/ManualPaymentSection'
import { PaymentMethodSection } from '@/modules/tenant/components/billing/PaymentMethodSection'
import { useTenantBilling } from '@/modules/tenant/hooks/useTenantBilling'
import { Skeleton } from 'boneyard-js/react'
import { AlertCircle } from 'lucide-react'

interface TenantBillingPanelProps {
  tenantId: string
}

/**
 * Cuerpo de la pantalla de facturación de un tenant (billing v2, "todo es base").
 * Lee el resumen agregado y arma las secciones. Sólo monta cuando el modal está
 * abierto (carga perezosa).
 *
 * Orden de secciones:
 *   1. ExpirationSection   — vencimientos y estado
 *   2. PaymentMethodSection — método de cobro activo
 *   3. EntitlementsSection  — plan de la organización
 *   4. ManualPaymentSection — registrar pago (sólo método manual)
 *   5. LedgerSection        — historial de pagos
 */
export function TenantBillingPanel({ tenantId }: TenantBillingPanelProps) {
  const {
    billing,
    changePaymentMethod,
    registerPayment,
    grantFree,
    revoke,
    applyPreferentialSwap,
    startTrial,
  } = useTenantBilling(tenantId)

  if (billing.isLoading) {
    return (
      <Skeleton name='tenant-billing' loading>
        <div
          className='space-y-4'
          aria-busy='true'
          aria-label='Cargando información de facturación'
        >
          {Array.from({ length: 4 }).map((_, index) => (
            <div key={index} className='h-28 w-full bg-muted/30' />
          ))}
        </div>
      </Skeleton>
    )
  }

  if (billing.isError || !billing.data) {
    return (
      <div
        role='alert'
        className='space-y-3 border border-destructive/20 bg-destructive/5 p-6 text-center'
      >
        <p className='flex items-center justify-center gap-2 text-sm text-destructive'>
          <AlertCircle className='h-4 w-4' aria-hidden />
          {resolveBillingError(billing.error, 'No se pudo cargar la facturación de la organización.')}
        </p>
        <Button type='button' variant='outline' size='sm' onClick={() => void billing.refetch()}>
          Reintentar
        </Button>
      </div>
    )
  }

  const summary = billing.data
  const isManualMethod = summary.payment_method !== 'card'

  // ¿La organización tiene acceso de cortesía vigente? Con un solo plan base ya no hay
  // una lista de módulos gratuitos que enumerar: es sí o no.
  const hasCourtesyAccess = summary.entitlements.some(
    (entitlement) => entitlement.source === 'super_admin_free' && isActiveEntitlement(entitlement)
  )

  const planName = summary.plan?.name ?? 'de la organización'

  return (
    <div className='space-y-5'>
      <ExpirationSection status={summary.status} entitlements={summary.entitlements} />

      <PaymentMethodSection
        currentMethod={summary.payment_method}
        changePaymentMethod={changePaymentMethod}
      />

      <EntitlementsSection
        tenantId={tenantId}
        plan={summary.plan}
        availableModules={summary.available_modules}
        availablePlans={summary.available_plans}
        entitlements={summary.entitlements}
        grantFree={grantFree}
        revoke={revoke}
        applyPreferentialSwap={applyPreferentialSwap}
        startTrial={startTrial}
      />

      {isManualMethod && (
        <ManualPaymentSection
          manualPayment={summary.manual_payment}
          hasCourtesyAccess={hasCourtesyAccess}
          planName={planName}
          registerPayment={registerPayment}
        />
      )}

      <LedgerSection ledger={summary.ledger} ledgerTotal={summary.ledger_total} />
    </div>
  )
}
