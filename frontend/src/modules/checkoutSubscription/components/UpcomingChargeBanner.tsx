import { sessionStore } from '@/shared/auth/sessionStore'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { CreditCard } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useStore } from 'zustand'

/** Días antes del primer cargo en los que se empieza a avisar. */
const WARN_WITHIN_DAYS = 7

/**
 * Aviso de que el primer cargo se acerca, visible en TODO el panel.
 *
 * La fecha del primer cargo sólo se veía en la pantalla de planes y en "Mi
 * suscripción", que son justamente las dos que el dueño deja de visitar en cuanto
 * su prueba arranca. El resultado era que el cargo podía llegar sin que nadie lo
 * hubiera visto venir — el origen clásico de la disputa "me cobraron sin avisar".
 *
 * Sólo aparece cuando hay algo que anunciar: prueba que se convierte sola y a
 * menos de una semana del cargo. Fuera de esa ventana no ocupa espacio.
 */
export function UpcomingChargeBanner() {
  const subscription = useStore(sessionStore, (s) => s.profile?.subscription)

  // API-owned (trial-countdown-utility): the frontend renders this number,
  // never computes it — see CalendarDays::until() in SubscriptionStateService.
  const daysLeft = subscription?.trial_days_left ?? null

  const shouldWarn =
    subscription?.status === 'trial' &&
    subscription.trial_auto_charges === true &&
    subscription.trial_ends_at != null &&
    daysLeft !== null &&
    daysLeft <= WARN_WITHIN_DAYS

  if (!shouldWarn || !subscription?.trial_ends_at) return null

  return (
    <div className='mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-none border border-border bg-card px-4 py-3 text-[13.5px]'>
      <CreditCard className='h-4 w-4 shrink-0 text-[var(--ink-3)]' aria-hidden />
      <p className='min-w-0 text-foreground'>
        {daysLeft === 0
          ? 'Hoy termina tu prueba y se hace el primer cargo de tu plan.'
          : `Tu prueba termina en ${daysLeft} ${daysLeft === 1 ? 'día' : 'días'} (${displayFormatDate(subscription.trial_ends_at, 'mediumDate')}) y se hace el primer cargo de tu plan.`}
      </p>
      <Link
        to='/subscription'
        className='ml-auto shrink-0 font-medium text-primary underline-offset-4 hover:underline'
      >
        Ver mi suscripción
      </Link>
    </div>
  )
}
