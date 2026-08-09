import { Badge } from '@/components/ui/badge'
import { TENANT_STATUS_CONFIG, type TenantStatus } from '@/modules/tenant/schema'
import type { IBillingEntitlement } from '@/modules/tenant/billing/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
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
  const now = Date.now()
  const dates = entitlements
    .map((entitlement) => entitlement.expires_at)
    .filter((value): value is string => Boolean(value))
    .filter((value) => new Date(value).getTime() >= now)
    .sort()
  return dates[0] ?? null
}

function isKnownStatus(status: string): status is TenantStatus {
  return status === 'activo' || status === 'pendiente_pago' || status === 'bloqueado'
}

/**
 * Muestra cuándo vence el acceso del clúster (el entitlement que primero expira)
 * y resalta si está bloqueado, explicando cómo se reactiva.
 */
export function ExpirationSection({ status, entitlements }: ExpirationSectionProps) {
  const earliest = getEarliestExpiration(entitlements)
  const statusConfig = isKnownStatus(status) ? TENANT_STATUS_CONFIG[status] : undefined
  const isBlocked = status === 'bloqueado'

  return (
    <section aria-labelledby="billing-expiration-heading" className="rounded-xl border border-border bg-card p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 id="billing-expiration-heading" className="text-base font-semibold text-foreground">
            Vencimiento del acceso
          </h3>
          <p className="mt-1 flex items-center gap-2 text-sm text-muted-foreground">
            <CalendarClock className="h-4 w-4 shrink-0" aria-hidden />
            {earliest ? (
              <span>
                El acceso vence el{' '}
                <span className="font-medium text-foreground">{displayFormatDate(earliest, 'mediumDate')}</span>.
              </span>
            ) : (
              <span>Sin vencimiento. El acceso no caduca.</span>
            )}
          </p>
        </div>
        <Badge variant="outline" className={statusConfig?.className}>
          {statusConfig?.label ?? status}
        </Badge>
      </div>

      {isBlocked && (
        <p
          role="alert"
          className="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300"
        >
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
          <span>
            El condominio está bloqueado por falta de pago. Registra un pago manual o reactiva el
            cobro con tarjeta para restablecer el acceso de los residentes.
          </span>
        </p>
      )}
    </section>
  )
}
