import { TenantStatusPill } from '@/modules/tenant/components/TenantStatusPill'
import type { IBillingEntitlement } from '@/modules/tenant/billing/schema'
import { Panel } from '@/shared/components/molecules/Panel'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { compareInstants, isInstantAtOrAfter } from '@/shared/utils/tenantTime'
import { AlertTriangle, CalendarClock } from 'lucide-react'

interface ExpirationSectionProps {
  status: string
  entitlements: IBillingEntitlement[]
}

/**
 * Devuelve el vencimiento más próximo AÚN VIGENTE (el que primero deja sin acceso)
 * o null si ninguno vence. Sólo considera fechas futuras — mismo criterio que
 * `isActiveEntitlement` — para no mostrar un addon ya vencido como vencimiento futuro.
 */
function getEarliestExpiration(entitlements: IBillingEntitlement[]): string | null {
  const dates = entitlements
    .map((entitlement) => entitlement.expires_at)
    .filter((value): value is string => Boolean(value))
    .filter((value) => isInstantAtOrAfter(value))
    .sort(compareInstants)
  return dates[0] ?? null
}

/**
 * Muestra cuándo vence el acceso de la organización (el entitlement que primero expira)
 * y resalta si está bloqueado, explicando cómo se reactiva.
 */
export function ExpirationSection({ status, entitlements }: ExpirationSectionProps) {
  const earliest = getEarliestExpiration(entitlements)
  const isBlocked = status === 'bloqueado'

  return (
    <Panel
      title='Vencimiento del acceso'
      description={
        <span className='flex items-center gap-2'>
          <CalendarClock className='h-4 w-4 shrink-0' aria-hidden />
          {earliest ? (
            <span>
              El acceso vence el{' '}
              <span className='font-medium text-foreground'>
                {displayFormatDate(earliest, 'mediumDate')}
              </span>
              .
            </span>
          ) : (
            <span>Sin vencimiento. El acceso no caduca.</span>
          )}
        </span>
      }
      actions={<TenantStatusPill status={status} />}
    >
      {isBlocked && (
        <p
          role='alert'
          className='flex items-start gap-2 border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive'
        >
          <AlertTriangle className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
          <span>
            La organización está bloqueado por falta de pago. Registra un pago manual o reactiva el
            cobro con tarjeta para restablecer el acceso de los residentes.
          </span>
        </p>
      )}
    </Panel>
  )
}
