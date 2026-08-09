import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type { IPlanInfo } from '@/modules/configuration/services/ConfigurationResource'
import { Callout } from '@/shared/components/atoms/Callout'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { AlertTriangle } from 'lucide-react'
import { Link } from 'react-router-dom'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'

interface StatusBadge {
  label: string
  className: string
}

function getStatusBadge(status: string): StatusBadge {
  switch (status) {
    case 'active':
      return {
        label: 'Activo',
        className: 'bg-[var(--green-bg)] text-[var(--green-deep)] border-transparent',
      }
    case 'pending_payment':
      return {
        label: 'Pago pendiente',
        className:
          'bg-amber-50 text-amber-700 border-transparent dark:bg-amber-950/30 dark:text-amber-400',
      }
    case 'blocked':
      return {
        label: 'Bloqueado',
        className: 'bg-destructive/10 text-destructive border-transparent',
      }
    default:
      return {
        label: status,
        className: 'bg-secondary text-secondary-foreground border-transparent',
      }
  }
}

function formatPrice(
  price: number | null | undefined,
  interval: string | null | undefined
): string | null {
  if (price == null) return null
  // Money is stored as bigint minor units (cents). Divide by 100 to get MXN.
  const mxn = (price / 100).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
  if (!interval) return mxn
  const intervalLabel = interval === 'month' ? 'mes' : interval === 'year' ? 'año' : interval
  return `${mxn} / ${intervalLabel}`
}

interface Props {
  plan: IPlanInfo | null
}

/**
 * Vista de solo lectura del plan y suscripción del condominio.
 * Muestra estado, nombre del plan, consumo de unidades y CTA condicional.
 */
export function PlanSummarySection({ plan }: Props) {
  const canManageSubscription = usePermissions('manage.subscription')

  if (!plan) {
    return (
      <div className={CARD}>
        <p className='py-6 text-center text-[13px] text-[var(--ink-3)]'>
          Sin información del plan.
        </p>
      </div>
    )
  }

  const statusBadge = getStatusBadge(plan.status)
  const formattedPrice = plan.plan ? formatPrice(plan.plan.price, plan.plan.interval) : null
  const unitLimit = plan.unit_limit ?? 0
  const progressPct = unitLimit > 0 ? Math.min((plan.unit_count / unitLimit) * 100, 100) : 0

  return (
    <div className={CARD}>
      <div className='space-y-5'>
        {/* ── Estado de la suscripción ── */}
        <div className='flex items-center gap-3'>
          <h3 className='min-w-[120px] text-[12.5px] font-medium text-[var(--ink-3)]'>Estado</h3>
          <Badge
            className={statusBadge.className}
            aria-label={`Estado del plan: ${statusBadge.label}`}
          >
            {statusBadge.label}
          </Badge>
        </div>

        <div className='h-px bg-[var(--line-2)]' />

        {/* ── Plan actual ── */}
        {plan.plan ? (
          <div className='space-y-0.5'>
            <p className='text-[12.5px] font-medium text-[var(--ink-3)]'>Plan actual</p>
            <p className='text-[18px] font-semibold text-[var(--ink-1)]'>{plan.plan.name}</p>
            {formattedPrice && <p className='text-[13px] text-[var(--ink-3)]'>{formattedPrice}</p>}
          </div>
        ) : (
          <p className='text-[13px] text-[var(--ink-3)]'>Aún no tienes un plan activo.</p>
        )}

        <div className='h-px bg-[var(--line-2)]' />

        {/* ── Uso de unidades ── */}
        <div className='space-y-2'>
          <p className='text-[12.5px] font-medium text-[var(--ink-3)]'>Uso de unidades</p>
          {unitLimit > 0 ? (
            <>
              <p className='text-[13px] text-[var(--ink-2)]'>
                <span className='font-semibold'>{plan.unit_count}</span>
                {' de '}
                <span className='font-semibold'>{unitLimit}</span>
                {' unidades'}
              </p>
              {/* Progress bar accesible */}
              <div
                role='progressbar'
                aria-valuenow={plan.unit_count}
                aria-valuemin={0}
                aria-valuemax={unitLimit}
                aria-label={`${plan.unit_count} de ${unitLimit} unidades utilizadas`}
                className='h-2 w-full overflow-hidden rounded-full bg-[var(--surface-2)]'
              >
                <div
                  className={`h-full rounded-full transition-all ${plan.units_within_limit ? 'bg-[var(--green-deep)]' : 'bg-destructive'}`}
                  style={{ width: `${progressPct}%` }}
                />
              </div>
            </>
          ) : (
            <p className='text-[13px] text-[var(--ink-3)]'>
              <span className='font-semibold'>{plan.unit_count}</span>
              {' unidades · Sin límite'}
            </p>
          )}

          {!plan.units_within_limit && (
            <Callout
              icon={AlertTriangle}
              className='mt-2 bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400'
            >
              Superaste el límite de unidades de tu plan. Considera actualizarlo.
            </Callout>
          )}
        </div>

        {/* ── CTA condicional ── */}
        {canManageSubscription && (
          <div className='flex justify-end border-t border-[var(--line-2)] pt-4'>
            <Button asChild size='sm' className='rounded-full'>
              <Link to='/subscription'>Cambiar plan</Link>
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
