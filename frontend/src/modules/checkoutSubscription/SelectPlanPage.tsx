import { BrandWordmark } from '@/components/auth/BrandWordmark'
import { Button } from '@/components/ui/button'
import { PlanBuilder } from '@/modules/checkoutSubscription/components/PlanBuilder'
import { CheckoutSubscriptionResource } from '@/modules/checkoutSubscription/services/CheckoutSubscriptionResource'
import {
  isPurchaseIntentTracked,
  markPurchaseIntentTracked,
  readPurchaseIntent,
  trackEvent,
} from '@/shared/analytics/trackEvent'
import { fetchSessionProfile } from '@/shared/auth/authService'
import type { SubscriptionStatus } from '@/shared/auth/Session'
import { sessionStore } from '@/shared/auth/sessionStore'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { CheckCircle2, Clock, Loader2, Lock } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { useStore } from 'zustand'

/**
 * Redirección al portal de facturación de Stripe (self-service: actualizar
 * tarjeta, ver facturas). Usado por `PaymentFailedRecovery` (estado c del
 * bloqueo — D8).
 *
 * Nota: el legacy `StatusAlert` (estados Stripe crudos canceled/past_due/
 * incomplete/incomplete_expired/unpaid) fue RETIRADO — colisionaba con los
 * tres estados canónicos de bloqueo (§8: `BLOCKED_COPY`), y `subscription.
 * status` NUNCA trae esos valores crudos (SubscriptionStateService solo
 * emite none/exempt/active/trial/expired), así que era código muerto que
 * además contradecía la doc pública (`acceso-en-pausa.mdx`).
 */
function useBillingPortalRedirect() {
  const [isRedirecting, setIsRedirecting] = useState(false)

  const redirectToPortal = useCallback(async () => {
    try {
      setIsRedirecting(true)
      const url = await CheckoutSubscriptionResource.getPortalUrl()
      if (!url) {
        toast.error('Error', 'No se pudo obtener el portal de facturación.')
        setIsRedirecting(false)
        return
      }
      window.location.href = url
    } catch {
      toast.error('Error', 'No se pudo abrir el portal de facturación.')
      setIsRedirecting(false)
    }
  }, [])

  return { isRedirecting, redirectToPortal }
}

function MemberBlockedView() {
  const handleLogout = useCallback(() => {
    sessionStore.getState().logout()
  }, [])

  return (
    <div className='flex min-h-svh flex-col bg-muted'>
      <header className='flex items-center justify-between border-b bg-background px-6 py-4'>
        <div className='flex items-center gap-2'>
          <Lock className='h-5 w-5 text-muted-foreground' />
          <span className='text-lg font-semibold'>Acceso restringido</span>
        </div>
        <Button variant='ghost' size='sm' onClick={handleLogout}>
          Cerrar sesión
        </Button>
      </header>

      <main className='flex flex-1 flex-col items-center justify-center p-6 md:p-10'>
        <div className='mx-auto w-full max-w-lg text-center'>
          <div className='mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-muted-foreground/10'>
            <Lock className='h-8 w-8 text-muted-foreground' />
          </div>

          <h1 className='text-2xl font-bold tracking-tight md:text-3xl'>
            El acceso está en pausa
          </h1>

          <p className='mt-4 text-muted-foreground leading-relaxed'>
            El propietario de la cuenta necesita actualizar la suscripción del organización. En cuanto
            lo haga, vuelves a entrar con normalidad. Tu información está completa y no se perdió
            nada.
          </p>

          {/* Antes esta pantalla sólo ofrecía "Cerrar sesión": quien más presiona
              para que se resuelva —los residentes— se quedaba sin nada que hacer y
              sin saber a quién acudir. Ahora tiene un aviso listo para enviar. */}
          <div className='mt-8 flex flex-col items-center gap-3'>
            <Button asChild className='rounded-full'>
              <a
                href={`mailto:?subject=${encodeURIComponent(
                  'El acceso a nuestro organización está en pausa'
                )}&body=${encodeURIComponent(
                  'Hola:\n\nEl acceso a la plataforma del organización está en pausa porque la suscripción necesita actualizarse. Solo el propietario de la cuenta puede hacerlo, desde Mi suscripción.\n\nGracias.'
                )}`}
              >
                Avisar al propietario
              </a>
            </Button>
            <Button variant='ghost' size='sm' onClick={handleLogout}>
              Cerrar sesión
            </Button>
          </div>

          <p className='mt-6 text-xs text-muted-foreground'>
            ¿Necesitas ayuda? Escríbenos a{' '}
            <a href='mailto:hola@edi.example.com' className='underline'>
              hola@edi.example.com
            </a>
            .
          </p>
        </div>
      </main>
    </div>
  )
}

export function SelectPlanPage() {
  const subscription = useStore(sessionStore, (s) => s.profile?.subscription)
  const isBlocked = subscription?.is_blocked ?? false
  const status = subscription?.status ?? 'none'
  const isOwner = subscription?.is_owner ?? false
  const trialEndsAt = subscription?.trial_ends_at ?? null
  const trialDaysLeft = subscription?.trial_days_left ?? null
  const isTrial = status === 'trial'
  // D8 — solo significativo cuando el organización está bloqueado: distingue "tu
  // tarjeta falló" (estado c) de "nunca elegiste un plan" / "tu prueba terminó".
  const paymentIssue = subscription?.payment_issue ?? false
  // La prueba de Stripe ya trae plan y tarjeta: se cobra sola al vencer y no exige
  // ninguna acción. La prueba LOCAL de cortesía no tiene ninguna de las dos, así que
  // ahí sí hay que contratar. Ambas reportan status==='trial'; solo esto las separa.
  const trialAutoCharges = subscription?.trial_auto_charges ?? false

  // Redirigir al dashboard SOLO si ya tiene un plan pagado activo.
  // El owner en trial puede entrar aquí para contratar antes de que venza.
  if (!isBlocked && !isTrial) {
    return <Navigate to='/dashboard' replace />
  }

  if (!isOwner) {
    return <MemberBlockedView />
  }

  return (
    <OwnerPlanView
      status={status}
      trialEndsAt={trialEndsAt}
      trialDaysLeft={trialDaysLeft}
      paymentIssue={paymentIssue}
      trialAutoCharges={trialAutoCharges}
    />
  )
}

function SuccessView() {
  const navigate = useNavigate()
  const subscription = useStore(sessionStore, (s) => s.profile?.subscription)
  const [confirmed, setConfirmed] = useState(false)
  const [timedOut, setTimedOut] = useState(false)
  const [retryCount, setRetryCount] = useState(0)
  const attemptsRef = useRef(0)
  // Evita disparar Purchase más de una vez (StrictMode / ticks superpuestos).
  const purchaseTrackedRef = useRef(false)
  // Evita disparar StartTrial más de una vez, mismas razones que purchaseTrackedRef.
  const trialTrackedRef = useRef(false)

  // El webhook de Stripe puede tardar unos segundos en procesarse.
  // Hacemos hasta 20 intentos cada 3 s (≈60 s) antes de mostrar el
  // mensaje de fallback con opciones manuales.
  // retryCount en deps permite que "Reintentar" reactive el polling.
  useEffect(() => {
    const MAX_ATTEMPTS = 20
    const INTERVAL_MS = 3000

    const poll = setInterval(async () => {
      attemptsRef.current += 1

      try {
        const profile = await fetchSessionProfile()
        sessionStore.getState().setProfile(profile)

        if (!profile.subscription?.is_blocked) {
          clearInterval(poll)
          if (!purchaseTrackedRef.current) {
            purchaseTrackedRef.current = true
            // Reusa el event_id generado en InitiateCheckout (persistido antes
            // del redirect a Stripe, SIN borrarlo acá) para deduplicar contra
            // el Purchase que ya disparó el webhook con ese mismo id vía
            // metadata del Checkout. `isPurchaseIntentTracked` es un marcador
            // DURABLE (localStorage) que evita re-disparar en un refresh o
            // remount de esta pantalla; si no hay intent persistido (visita
            // directa/de nuevo), NO se dispara un Purchase con id aleatorio —
            // el webhook ya lo cubre server-side.
            const intent = readPurchaseIntent()
            if (intent && !isPurchaseIntentTracked(intent.eventId)) {
              markPurchaseIntentTracked(intent.eventId)
              void trackEvent(
                'Purchase',
                { value: intent.value, currency: intent.currency ?? 'MXN' },
                undefined,
                intent.eventId
              )
            }
          }
          // D7/D2 — StartTrial se dispara ACÁ, no en el registro: recién ahora
          // sabemos que el checkout realmente arrancó un trial (D1 — un organización
          // que ya tuvo una entitlement antes es cobrado de inmediato, sin
          // trial). Un registro abandonado antes de pagar nunca dispara esto.
          if (!trialTrackedRef.current && profile.subscription?.status === 'trial') {
            trialTrackedRef.current = true
            void trackEvent('StartTrial')
          }
          setConfirmed(true)
          return
        }
      } catch {
        /* reintentar en el siguiente tick */
      }

      if (attemptsRef.current >= MAX_ATTEMPTS) {
        clearInterval(poll)
        setTimedOut(true)
      }
    }, INTERVAL_MS)

    return () => clearInterval(poll)
  }, [retryCount])

  // NO se redirige solo. Esta pantalla carga el dato más importante de todo el
  // alta —la FECHA DEL PRIMER CARGO— y es el único momento en que el cliente lo
  // ve. Un timeout de 2s lo borraba antes de que alcanzara a leerlo, que es
  // justo lo que después produce el "nunca me dijeron que me iban a cobrar".
  // Ahora el cliente cierra la pantalla cuando terminó de leer.

  if (timedOut) {
    return (
      <div className='flex min-h-svh flex-col items-center justify-center bg-muted p-6'>
        <div className='mx-auto w-full max-w-md text-center'>
          <div className='mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/30'>
            <CheckCircle2 className='h-10 w-10 text-yellow-600 dark:text-yellow-400' />
          </div>

          <h1 className='text-2xl font-bold tracking-tight md:text-3xl'>Tu prueba está en camino</h1>

          <p className='mt-3 text-muted-foreground leading-relaxed'>
            Completaste el checkout y tu prueba de 30 días está por activarse, pero la confirmación
            está tardando más de lo habitual. Puedes reintentar o ir al panel directamente — tu
            acceso estará disponible en breve.
          </p>

          <div className='mt-8 flex flex-col items-center gap-3 sm:flex-row sm:justify-center'>
            <Button
              onClick={() => {
                attemptsRef.current = 0
                setTimedOut(false)
                setRetryCount((c) => c + 1)
              }}
            >
              Reintentar
            </Button>
            <Button variant='outline' onClick={() => navigate('/dashboard', { replace: true })}>
              Ir al panel
            </Button>
          </div>
        </div>
      </div>
    )
  }

  // D2/D7 — el checkout de un organización nuevo SIEMPRE arranca en trial (sin
  // cargo); "Pago exitoso" sería falso ahí. Un organización que ya tuvo una
  // entitlement antes (cancela y recontrata) es cobrado de inmediato — para
  // ese caso el "pago exitoso" original sigue siendo la verdad.
  const isTrialActivation = confirmed && subscription?.status === 'trial'
  const trialEndsAt = subscription?.trial_ends_at ?? null

  const title = isTrialActivation ? 'Listo, tu prueba empezó' : 'Pago exitoso'
  const body = isTrialActivation
    ? `Tienes 30 días gratis con todo incluido. Tu primer cargo será el ${
        trialEndsAt ? displayFormatDate(trialEndsAt, 'mediumDate') : 'fin de tu prueba'
      }; puedes cancelar antes desde Configuración y no se te cobra nada.`
    : `Tu suscripción ha sido activada correctamente.${confirmed ? ' Redirigiendo al panel...' : ' Estamos configurando tu cuenta...'}`

  return (
    <div className='flex min-h-svh flex-col items-center justify-center bg-muted p-6'>
      <div className='mx-auto w-full max-w-md text-center'>
        <div className='mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30'>
          <CheckCircle2 className='h-10 w-10 text-emerald-600 dark:text-emerald-400' />
        </div>

        <h1 className='text-2xl font-bold tracking-tight md:text-3xl'>{title}</h1>

        <p className='mt-3 text-muted-foreground leading-relaxed'>{body}</p>

        {/* La fecha del primer cargo se repite como dato destacado: en el párrafo
            va dentro de una frase, y una frase no se recuerda. Un número grande sí. */}
        {isTrialActivation && trialEndsAt && (
          <div className='mt-6 rounded-[14px] border bg-card px-6 py-5'>
            <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
              Tu primer cargo
            </span>
            <p className='mt-1 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums'>
              {displayFormatDate(trialEndsAt, 'mediumDate')}
            </p>
          </div>
        )}

        {confirmed ? (
          <Button
            size='lg'
            className='mt-8 w-full rounded-full'
            onClick={() => navigate('/dashboard', { replace: true })}
          >
            Entrar al panel
          </Button>
        ) : (
          <div className='mt-6 flex items-center justify-center gap-2 text-sm text-muted-foreground'>
            <Loader2 className='h-4 w-4 animate-spin' />
            <span>Confirmando suscripción...</span>
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * Copy de las tres causas de bloqueo distintas (D8 / copy.md §8) — nunca deben
 * sonar igual: "todavía no empiezas" y "te acabamos de cortar el servicio
 * porque tu tarjeta falló" son emocionalmente muy distintas.
 */
const BLOCKED_COPY = {
  none: {
    title: 'Elige tu plan para empezar',
    body: 'Tu organización ya está creado. Elige un plan para activarlo y llevarte 30 días gratis; hoy no pagas nada.',
  },
  expired: {
    title: 'Tu prueba terminó',
    body: 'Se acabaron tus 30 días gratis. Elige un plan para recuperar el acceso — tu información sigue completa y no se perdió nada.',
  },
  payment_failed: {
    title: 'No pudimos procesar tu pago',
    body: 'Tu banco rechazó el cargo, así que el acceso de tu organización está en pausa. Actualiza tu tarjeta y se reactiva en cuanto el pago se confirme.',
  },
} as const

/**
 * Estado (c) — recuperación de pago. Es el ÚNICO foco de su pantalla: cuando el
 * cobro falla, la tarea del dueño es actualizar su tarjeta, no volver a elegir
 * plan. Por eso el armador de planes NO se muestra detrás; "Cambiar de plan" lo
 * revela para quien de verdad quiera cambiarlo.
 */
function PaymentFailedRecovery({ onChangePlan }: { onChangePlan: () => void }) {
  const { isRedirecting, redirectToPortal } = useBillingPortalRedirect()

  return (
    <div className='mx-auto flex max-w-md flex-col items-center gap-5 text-center'>
      <div className='flex w-full flex-col items-center gap-3'>
        <Button
          size='lg'
          onClick={() => void redirectToPortal()}
          disabled={isRedirecting}
          className='w-full rounded-full'
        >
          {isRedirecting ? (
            <>
              <Loader2 className='mr-2 h-4 w-4 animate-spin' />
              Redirigiendo...
            </>
          ) : (
            'Actualizar mi tarjeta'
          )}
        </Button>
        <button
          type='button'
          onClick={onChangePlan}
          className='min-h-11 px-3 text-[13.5px] font-medium text-[var(--ink-3)] underline-offset-4 transition-colors hover:text-foreground hover:underline'
        >
          Prefiero cambiar de plan
        </button>
      </div>
      <p className='text-xs text-muted-foreground'>
        ¿Tu tarjeta está bien y aun así falla? Escríbenos a{' '}
        <a href='mailto:hola@edi.example.com' className='underline'>
          hola@edi.example.com
        </a>
        .
      </p>
    </div>
  )
}

/**
 * Estado de prueba en marcha (Stripe). El dueño llegó aquí por su cuenta y NO
 * tiene nada que resolver: el foco es confirmarle que todo está en orden y
 * cuándo se le cobra. El armador de planes queda detrás de una acción explícita
 * para no simular una tarea que no existe.
 */
function TrialStatusCard({
  trialEndsAt,
  trialDaysLeft,
  onChangePlan,
}: {
  trialEndsAt: string
  trialDaysLeft: number | null
  onChangePlan: () => void
}) {
  return (
    <div className='mx-auto flex max-w-md flex-col items-center gap-5 rounded-[14px] border bg-card p-7 text-center'>
      <div className='flex flex-col items-center gap-1.5'>
        <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
          Tu primer cargo
        </span>
        <span className='text-[28px] font-semibold leading-none tracking-[-0.02em] tabular-nums'>
          {displayFormatDate(trialEndsAt, 'mediumDate')}
        </span>
        {trialDaysLeft !== null && (
          <span className='text-[13.5px] text-[var(--ink-3)]'>
            {trialDaysLeft > 0
              ? `Te quedan ${trialDaysLeft} ${trialDaysLeft === 1 ? 'día' : 'días'} de prueba`
              : 'Tu prueba termina hoy'}
          </span>
        )}
      </div>
      <p className='text-[13.5px] leading-relaxed text-muted-foreground'>
        No tienes que hacer nada. Si no quieres continuar, cancela antes desde Mi suscripción y no
        se te cobra.
      </p>
      <button
        type='button'
        onClick={onChangePlan}
        className='min-h-11 px-3 text-[13.5px] font-medium text-[var(--ink-3)] underline-offset-4 transition-colors hover:text-foreground hover:underline'
      >
        Cambiar de plan
      </button>
    </div>
  )
}

function OwnerPlanView({
  status,
  trialEndsAt,
  trialDaysLeft,
  paymentIssue,
  trialAutoCharges,
}: {
  status: SubscriptionStatus
  trialEndsAt: string | null
  trialDaysLeft: number | null
  paymentIssue: boolean
  trialAutoCharges: boolean
}) {
  const [searchParams, setSearchParams] = useSearchParams()
  const navigate = useNavigate()
  const planBuilderRef = useRef<HTMLDivElement>(null)

  const isSuccess = searchParams.get('success') === 'true'
  const isCanceled = searchParams.get('canceled') === 'true'
  const cancelHandledRef = useRef(false)

  useEffect(() => {
    if (isCanceled && !cancelHandledRef.current) {
      cancelHandledRef.current = true
      toast.info('Pago cancelado', 'Puedes seleccionar un plan cuando estés listo.')
      setSearchParams({}, { replace: true })
    }
  }, [isCanceled, setSearchParams])

  const handleLogout = useCallback(() => {
    sessionStore.getState().logout()
  }, [])

  // Hoisted above the `isSuccess` early return (react-hooks/rules-of-hooks):
  // hooks and the pure derivations they depend on must run unconditionally on
  // every render, even though their values are simply unused while rendering
  // <SuccessView />.
  //
  // Status 'trial' es el ÚNICO caso no bloqueado que llega a esta vista (el
  // dueño navega para contratar antes de que venza) — conserva su copy actual.
  // Los otros dos ('none', 'expired') SIEMPRE están bloqueados, y 'expired' se
  // divide en dos causas distintas según payment_issue (D8 / copy.md §8).
  const isTrialBrowsing = status === 'trial'
  const blockedReason: keyof typeof BLOCKED_COPY | null = isTrialBrowsing
    ? null
    : status === 'none'
      ? 'none'
      : paymentIssue
        ? 'payment_failed'
        : 'expired'

  // UN SOLO FOCO POR PANTALLA. El armador de planes es la tarea sólo cuando
  // elegir plan ES lo que el dueño vino a hacer: nunca eligió uno, o su prueba
  // terminó sin contratar. En los otros dos casos su tarea es OTRA —
  // actualizar la tarjeta (pago fallido) o nada en absoluto (prueba en marcha)—
  // y dejar seis planes con cotización debajo del mensaje sugería una acción
  // equivocada justo en el peor momento. Ahí el armador espera detrás de una
  // acción explícita.
  const planPickerIsTheTask =
    blockedReason === 'none' ||
    blockedReason === 'expired' ||
    // Prueba de cortesía: no se convierte sola, contratar SÍ es la tarea.
    (isTrialBrowsing && !trialAutoCharges)
  const [showPlans, setShowPlans] = useState(planPickerIsTheTask)
  const [currentPlanKey, setCurrentPlanKey] = useState<string | null>(null)

  if (isSuccess) {
    return <SuccessView />
  }

  const headline = blockedReason ? BLOCKED_COPY[blockedReason] : null

  // Cuando el organización NO está bloqueado y pide ver planes, la pantalla CAMBIA de
  // vista en vez de crecer hacia abajo. Apilarlas dejaba en pantalla la tarjeta
  // "tu primer cargo · $950 (Esencial)" junto a "Tu cotización · Plan Básico ·
  // $450": dos precios distintos del mismo organización, uno real y otro hipotético,
  // sin nada que dijera cuál era cuál.
  // `!planPickerIsTheTask` es la condición que lo distingue de "el selector ya venía
  // abierto": en la prueba de CORTESÍA elegir plan es la tarea desde el inicio, así
  // que ahí el encabezado debe seguir siendo el suyo ("contrata antes de que venza"),
  // no el de un cambio de plan voluntario.
  const isChangingPlan = showPlans && !blockedReason && !planPickerIsTheTask

  const revealPlanBuilder = () => {
    setShowPlans(true)

    // Se preselecciona el plan que el organización YA tiene. Sin esto el armador
    // caía en el primer plan del catálogo y ofrecía "Plan Básico $450" a quien
    // contrató Esencial de $950: parecía una degradación que nadie pidió, y en
    // ningún lado se leía cuál era el plan vigente.
    // Se pasa como prop y NO por `?plan=` en la URL: el armador lee ese parámetro
    // una sola vez al montar, y para entonces esta respuesta todavía no llegó.
    // Sólo se consulta al entrar aquí, no al montar la pantalla.
    void CheckoutSubscriptionResource.getMySubscription()
      .then((data) => setCurrentPlanKey(data?.currentPlan?.key ?? null))
      .catch(() => {
        // Si falla, el armador se queda con su fallback: no vale romper el flujo
        // de cambio de plan por no poder preseleccionar.
      })

    // El armador aún no está montado en este tick: se difiere el scroll al
    // siguiente frame para que exista el nodo al que desplazarse.
    requestAnimationFrame(() =>
      planBuilderRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    )
  }

  return (
    <div className='flex min-h-svh flex-col bg-muted'>
      <header className='flex items-center justify-between border-b border-border bg-card px-6 py-4'>
        <BrandWordmark size='sm' />
        <div className='flex items-center gap-1'>
          {/* Un organización en prueba NO está bloqueado: entró por su cuenta y debe
              poder volver. Antes la única salida era cerrar sesión. */}
          {!blockedReason && (
            <Button variant='ghost' size='sm' onClick={() => navigate('/dashboard')}>
              Volver al panel
            </Button>
          )}
          <Button variant='ghost' size='sm' onClick={handleLogout}>
            Cerrar sesión
          </Button>
        </div>
      </header>

      <main className='flex flex-1 flex-col items-center justify-center p-6 md:p-10'>
        <div className='mx-auto w-full max-w-5xl'>
          <div className='mb-8 text-center'>
            {/* Sentido de progreso: ahora que dar tarjeta es obligatorio, no saber
                cuánto falta es de las principales causas de abandono en el alta. */}
            {blockedReason === 'none' && (
              <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                Paso 2 de 2
              </p>
            )}
            <h1 className='text-3xl font-semibold tracking-[-0.02em]'>
              {isChangingPlan ? (
                <>
                  Cambia el plan de tu{' '}
                  <b className='font-semibold text-[var(--green)]'>organización</b>
                </>
              ) : headline ? (
                headline.title
              ) : trialAutoCharges ? (
                <>
                  Tu prueba está <b className='font-semibold text-[var(--green)]'>en marcha</b>
                </>
              ) : (
                <>
                  Elige el plan de tu{' '}
                  <b className='font-semibold text-[var(--green)]'>organización</b>
                </>
              )}
            </h1>
            <p className='mt-2.5 text-[15px] text-[var(--ink-3)]'>
              {/* Sin `headline` el organización NO está bloqueado: está en prueba y entró por
                  su cuenta. El copy anterior ("Renueva el plan… El acceso de tu equipo
                  depende de una suscripción activa") era el de un organización vencido y
                  alarmaba sin motivo a quien tiene todo en orden. */}
              {isChangingPlan
                ? 'Tu prueba sigue corriendo. El plan que elijas aplica desde tu primer cargo.'
                : headline
                  ? headline.body
                  : trialAutoCharges
                    ? 'Tienes acceso completo a la plataforma.'
                    : 'Estás en tu período de prueba. Contrata un plan para conservar el acceso cuando termine.'}
            </p>

            {isChangingPlan && (
              <button
                type='button'
                onClick={() => setShowPlans(false)}
                className='mt-4 inline-flex min-h-11 items-center px-3 text-[13.5px] font-medium text-[var(--ink-3)] underline-offset-4 transition-colors hover:text-foreground hover:underline'
              >
                ← Volver al estado de mi prueba
              </button>
            )}
          </div>

          {blockedReason === 'payment_failed' && (
            <PaymentFailedRecovery onChangePlan={revealPlanBuilder} />
          )}

          {/* Prueba que se convierte sola: no hay tarea, sólo la fecha del cargo.
              Desaparece al entrar en modo cambio — si no, su "$950 el 6 de sep"
              queda arriba de una cotización de otro plan y ninguno de los dos
              precios se entiende. */}
          {isTrialBrowsing && trialAutoCharges && trialEndsAt && !isChangingPlan && (
            <TrialStatusCard
              trialEndsAt={trialEndsAt}
              trialDaysLeft={trialDaysLeft}
              onChangePlan={revealPlanBuilder}
            />
          )}

          {/* Prueba LOCAL de cortesía: sí hay que contratar antes de que venza, así
              que el aviso conserva su forma de banner y el armador queda a la vista. */}
          {isTrialBrowsing && !trialAutoCharges && trialEndsAt && (
            <div className='mb-6 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-900/20 dark:text-blue-200'>
              <Clock className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
              <span>
                Tu período de prueba vence el{' '}
                <strong>{displayFormatDate(trialEndsAt, 'mediumDate')}</strong>
                {trialDaysLeft !== null && trialDaysLeft > 0
                  ? ` (${trialDaysLeft} ${trialDaysLeft === 1 ? 'día' : 'días'} restante${trialDaysLeft === 1 ? '' : 's'})`
                  : trialDaysLeft === 0
                    ? ' (vence hoy)'
                    : ''}
                {'. '}
                Contrata un plan para mantener el acceso cuando finalice.
              </span>
            </div>
          )}

          {showPlans && (
            <div ref={planBuilderRef}>
              <PlanBuilder preselectPlanKey={currentPlanKey} />
            </div>
          )}
        </div>
      </main>
    </div>
  )
}
