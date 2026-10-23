import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { IModuleTrial } from '@/modules/checkoutSubscription/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Clock } from 'lucide-react'
import { Link } from 'react-router-dom'

interface TrialExpiryBannerProps {
  trials: IModuleTrial[]
  /**
   * ¿Puede el usuario contratar? `POST /billing/checkout` está reservado al dueño de
   * la cuenta (403 `not_account_owner`), así que a un no-dueño se le informa del
   * vencimiento sin ofrecerle un CTA que no puede completar.
   */
  canContract: boolean
}

interface ExpiryDisplay {
  text: string
  className: string
}

function getExpiryDisplay(trial: IModuleTrial): ExpiryDisplay {
  if (trial.days_left === null) {
    return {
      text: 'Prueba activa',
      className: 'bg-primary/10 text-primary',
    }
  }
  if (trial.days_left === 0) {
    return {
      text: 'Vence hoy',
      className: 'bg-destructive/10 text-destructive',
    }
  }
  if (trial.days_left <= 3) {
    return {
      text: `Vence en ${trial.days_left} día${trial.days_left === 1 ? '' : 's'}`,
      className: 'bg-secondary text-muted-foreground',
    }
  }
  return {
    text: `Prueba: vence en ${trial.days_left} días`,
    className: 'bg-primary/10 text-primary',
  }
}

/**
 * Banner de periodo de prueba para la pantalla "Mi suscripción". Se muestra SOLO cuando
 * hay al menos una prueba activa (`trials.length > 0`). Lleva CTA a /select-plan: la
 * prueba vence y el dueño tiene que poder contratar desde el mismo aviso.
 */
export function TrialExpiryBanner({ trials, canContract }: TrialExpiryBannerProps) {
  if (trials.length === 0) return null

  return (
    <Card className='rounded-none border-border bg-card'>
      <CardHeader className='pb-3'>
        <CardTitle className='flex items-center gap-2 text-base text-foreground'>
          <Clock className='h-4 w-4 shrink-0' />
          Tu periodo de prueba
        </CardTitle>
      </CardHeader>
      <CardContent>
        <ul className='space-y-3'>
          {trials.map((trial) => {
            const expiry = getExpiryDisplay(trial)
            return (
              <li
                key={trial.module_key}
                className='flex flex-wrap items-center justify-between gap-3 border border-border bg-background p-3'
              >
                <div className='flex flex-col gap-1.5'>
                  <span className='text-sm font-medium'>{trial.module_name}</span>
                  <div className='flex flex-wrap items-center gap-2'>
                    <span
                      className={`inline-flex items-center rounded-none px-2 py-0.5 text-xs font-semibold ${expiry.className}`}
                    >
                      {expiry.text}
                    </span>
                    {trial.expires_at && (
                      <span className='font-mono text-xs tabular-nums text-muted-foreground'>
                        ({displayFormatDate(trial.expires_at, 'mediumDate')})
                      </span>
                    )}
                  </div>
                </div>
              </li>
            )
          })}
        </ul>
        <div className='mt-4 flex flex-wrap items-center gap-3'>
          {canContract && (
            <Button asChild size='sm'>
              <Link to='/select-plan'>Contratar un plan</Link>
            </Button>
          )}
          <span className='text-xs text-muted-foreground'>
            {canContract
              ? 'Al terminar la prueba se suspende el acceso hasta que contrates un plan.'
              : 'Al terminar la prueba se suspende el acceso hasta que el propietario de la cuenta contrate un plan.'}
          </span>
        </div>
      </CardContent>
    </Card>
  )
}
