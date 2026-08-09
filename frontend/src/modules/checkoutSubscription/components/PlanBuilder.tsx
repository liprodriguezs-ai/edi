import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import { useBillingQuoter } from '@/modules/checkoutSubscription/hooks/useBillingQuoter'
import {
  planBandLabel,
  planFeatures,
  planForUnitCount,
  planPriceFor,
} from '@/modules/checkoutSubscription/planSelection'
import type { IBillingPlan, IPlanQuote } from '@/modules/checkoutSubscription/schema'
import { Skeleton } from 'boneyard-js/react'
import { AlertCircle, Check, ChevronDown, Loader2, Mail, MessageCircle, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'

/**
 * Atajo: el cliente escribe cuántas casas administra y se le marca la banda que le
 * toca. Es el mismo criterio que usa el backend (`planForUnits`), resuelto aquí con
 * las bandas que el catálogo ya expone — sin llamada extra.
 *
 * Existe porque el precio va por bandas y la grilla venía con el plan más chico
 * preseleccionado: quien administra 80 casas y no se fija contrata el Básico (1–25)
 * y descubre el error cuando choca con el tope de unidades, ya trabajando.
 */
function UnitCountShortcut({
  plans,
  selectedPlanKey,
  onPick,
}: {
  plans: IBillingPlan[]
  selectedPlanKey: string | null
  onPick: (key: string) => void
}) {
  const [raw, setRaw] = useState('')

  const units = Number.parseInt(raw, 10)
  const hasUnits = raw !== '' && Number.isFinite(units) && units > 0

  const match = hasUnits ? planForUnitCount(plans, units) : null

  // Sólo se mueve la selección si el plan sugerido NO es el que ya está elegido,
  // para no pelear con quien está explorando la grilla a mano.
  const alreadyOnMatch = match ? match.key === selectedPlanKey : false

  return (
    <div className='rounded-[10px] border border-border bg-muted/30 p-4'>
      <label htmlFor='unit-count' className='text-[13px] font-semibold text-foreground'>
        ¿Cuántas casas o departamentos administras?
      </label>
      <p className='mt-1 text-[12.5px] text-muted-foreground'>
        Te marcamos el plan que te toca. También puedes elegirlo tú abajo.
      </p>
      <div className='mt-3 flex items-center gap-2'>
        <input
          id='unit-count'
          type='number'
          inputMode='numeric'
          min={1}
          value={raw}
          onChange={(e) => setRaw(e.target.value)}
          placeholder='Ej. 80'
          className='h-10 w-32 rounded-[8px] border border-border bg-background px-3 text-sm tabular-nums outline-none transition-colors focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30'
        />
        {match && !alreadyOnMatch && (
          <Button
            type='button'
            variant='outline'
            className='h-10 rounded-full'
            onClick={() => onPick(match.key)}
          >
            Usar {match.name}
          </Button>
        )}
      </div>
      {hasUnits && match && (
        <p className='mt-2.5 text-[12.5px] text-foreground'>
          {alreadyOnMatch ? (
            <>
              <Check className='mr-1 inline h-3.5 w-3.5 text-[var(--g)]' aria-hidden />
              Con {units} unidades te toca el plan <strong>{match.name}</strong>, que es el que
              tienes elegido.
            </>
          ) : (
            <>
              Con {units} unidades te toca el plan <strong>{match.name}</strong>.
            </>
          )}
        </p>
      )}
      {hasUnits && !match && (
        <p className='mt-2.5 text-[12.5px] text-muted-foreground'>
          No encontramos una banda para ese número. Elige "A medida" y te armamos una propuesta.
        </p>
      )}
    </div>
  )
}

/**
 * Armado de plan. El usuario elige un PLAN (catálogo público) y ve el precio en vivo;
 * TODO lo que ofrece la plataforma va incluido en el plan (ya no se suman módulos).
 *
 * La grilla de planes es el ÚNICO control: cada tarjeta muestra su banda de unidades
 * ("Ideal para 51–100 unidades"), así que ya no se pregunta el número de unidades por
 * separado. Los organizaciones que no caben en ninguna banda eligen la tarjeta "A medida",
 * que reemplaza la cotización por la card "Contáctanos".
 *
 * El cálculo es autoridad del backend (POST /billing/plan-quote); el cobro abre
 * Stripe Checkout (POST /billing/checkout).
 */
export function PlanBuilder({ preselectPlanKey = null }: { preselectPlanKey?: string | null } = {}) {
  const q = useBillingQuoter()
  const preselectedRef = useRef(false)

  // Preselecciona el plan VIGENTE del organización cuando el dueño entra a cambiarlo.
  // Se aplica una sola vez y sólo si el plan existe en el catálogo: después de eso
  // manda el usuario, y volver a forzarlo le arrebataría la selección mientras
  // compara opciones.
  useEffect(() => {
    if (preselectedRef.current) return
    if (!preselectPlanKey || q.isLoadingCatalog) return
    if (!q.plans.some((p) => p.key === preselectPlanKey)) return

    preselectedRef.current = true
    q.selectPlan(preselectPlanKey)
  }, [preselectPlanKey, q])

  if (q.isLoadingCatalog) {
    return <PlanBuilderSkeleton />
  }

  if (q.catalogError) {
    return (
      <div
        role='alert'
        className='mx-auto max-w-md rounded-lg border border-destructive/20 bg-destructive/5 p-6 text-center text-sm text-destructive'
      >
        {q.catalogError}
      </div>
    )
  }

  const currency = q.quote?.currency ?? q.catalog?.currency ?? 'MXN'
  const periodLabel = q.billingInterval === 'year' ? '/ año' : '/ mes'
  const includedFeatures = planFeatures(q.selectedPlan)

  return (
    <div className='grid gap-6 lg:grid-cols-[1.1fr_0.9fr]'>
      {/* Columna de controles */}
      <div className='rounded-xl border border-border bg-card p-6 sm:p-7'>
        {/* Atajo por número de unidades. El precio va por bandas (1–25, 26–50…),
            así que sin esto el cliente tiene que saber su número Y mapearlo a una
            banda a ojo — y como viene preseleccionado el plan más chico, quien
            administra 80 casas se lleva el Básico y choca con el tope de unidades
            cuando ya está trabajando. Escribir el número resuelve las dos cosas. */}
        <UnitCountShortcut plans={q.plans} onPick={q.selectPlan} selectedPlanKey={q.selectedPlanKey} />

        {/* Planes: cada tarjeta muestra su banda de unidades. */}
        <div className='mt-7'>
          <p className='text-[13px] font-semibold text-foreground'>Elige tu plan</p>
          <p className='mt-1 text-[12.5px] text-muted-foreground'>
            Si tienes más unidades de las que cubre el plan más grande, elige "A medida" y te armamos
            una propuesta.
          </p>
          <div className='mt-3 grid gap-2.5 sm:grid-cols-2'>
            {q.plans.map((plan) => (
              <PlanOption
                key={plan.key}
                plan={plan}
                selected={q.selectedPlanKey === plan.key}
                isCurrent={plan.key === preselectPlanKey}
                onSelect={() => q.selectPlan(plan.key)}
              />
            ))}
          </div>
        </div>

        {/* Intervalo: sin precio de lista el intervalo no aplica */}
        {!q.isContactSales && (
          <fieldset className='mt-7'>
            <legend className='text-[13px] font-semibold text-foreground'>Facturación</legend>
            <div
              role='group'
              aria-label='Intervalo de facturación'
              className='mt-2 flex w-full gap-1 rounded-full border border-border bg-muted/40 p-1.5'
            >
              <IntervalButton
                active={q.billingInterval === 'month'}
                onClick={() => q.setBillingInterval('month')}
              >
                Mensual
              </IntervalButton>
              <IntervalButton
                active={q.billingInterval === 'year'}
                onClick={() => q.setBillingInterval('year')}
              >
                {/* El 5% de descuento anual existía pero no se veía en ningún lado:
                    había que cambiar el interruptor para descubrirlo. Anunciarlo aquí
                    es lo que hace que alguien lo considere. */}
                Anual
                <span className='ml-1.5 rounded-full bg-[var(--g)]/12 px-1.5 py-0.5 text-[10.5px] font-semibold text-[var(--g)]'>
                  −5%
                </span>
              </IntervalButton>
            </div>
            <p className='mt-2 text-[12px] text-muted-foreground'>
              El pago anual tiene 5% de descuento sobre el precio de lista.
            </p>
          </fieldset>
        )}

        {/* Todo lo que incluye el plan (sin estado on/off, sin precio por módulo) */}
        <IncludedFeatures features={includedFeatures} />
      </div>

      {/* Columna derecha: cotización o card de contacto. Sticky en desktop para que no
          se pierda con el scroll de la columna izquierda. */}
      <div className='flex flex-col rounded-xl border border-border bg-foreground p-6 text-background sm:p-7 lg:sticky lg:top-6 lg:self-start lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto'>
        {q.isContactSales ? (
          <ContactSalesCard
            planName={q.selectedPlan?.name ?? 'A medida'}
            mailto={q.contactMailto}
            whatsapp={q.contactWhatsapp}
          />
        ) : (
          <>
            <p className='text-[12px] font-semibold uppercase tracking-[0.18em] text-background/60'>
              Tu cotización
            </p>

            {q.quoteError ? (
              <div className='mt-5'>
                <p role='alert' className='text-sm text-red-300'>
                  No pudimos calcular el precio ahora mismo.
                </p>
                <button
                  type='button'
                  onClick={q.retryQuote}
                  className='mt-2 inline-flex items-center text-[13px] font-semibold text-background underline decoration-from-font underline-offset-[3px] transition-colors hover:text-background/80'
                >
                  Reintentar
                </button>
              </div>
            ) : (
              <QuoteBreakdown
                currency={currency}
                periodLabel={periodLabel}
                isQuoting={q.isQuoting}
                quote={q.quote}
                plan={q.selectedPlan}
                interval={q.billingInterval}
              />
            )}

            {/* Acción */}
            <div className='mt-auto pt-6'>
              {q.checkoutError && (
                <div
                  role='alert'
                  className='mb-4 flex items-start gap-2 rounded-lg bg-background/10 p-3 text-[13px] text-background'
                >
                  <AlertCircle className='mt-0.5 h-4 w-4 shrink-0' />
                  <span className='flex-1'>{q.checkoutError}</span>
                  <button
                    type='button'
                    onClick={q.clearCheckoutError}
                    aria-label='Cerrar error'
                    className='shrink-0 text-background/50 transition-colors hover:text-background'
                  >
                    <X className='h-4 w-4' />
                  </button>
                </div>
              )}

              <Button
                type='button'
                onClick={() => void q.checkout()}
                disabled={
                  q.isCheckingOut ||
                  q.isQuoting ||
                  q.quoteError ||
                  !q.selectedPlanKey ||
                  !q.isPurchasable
                }
                className='w-full rounded-full bg-background py-3 text-sm font-semibold text-foreground hover:bg-background/90'
              >
                {q.isCheckingOut ? (
                  <>
                    <Loader2 className='mr-2 h-4 w-4 animate-spin' />
                    Redirigiendo a pago…
                  </>
                ) : !q.isPurchasable ? (
                  'Temporalmente no disponible'
                ) : (
                  'Contratar'
                )}
              </Button>
              <p className='mt-3 text-center text-[12px] text-background/50'>
                {q.isPurchasable
                  ? 'Pago seguro con Stripe. Puedes cambiar de plan cuando quieras.'
                  : 'Este plan no se puede contratar en línea ahora mismo. Elige otro plan o contáctanos.'}
              </p>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function IncludedFeatures({ features }: { features: string[] }) {
  // En mobile la lista se colapsa para no empujar la cotización fuera de pantalla.
  const [expanded, setExpanded] = useState(false)

  if (features.length === 0) {
    return null
  }

  return (
    <div className='mt-7'>
      <p className='text-[13px] font-semibold text-foreground'>Todo lo que incluye</p>
      <p className='mt-1 text-[12.5px] text-muted-foreground'>
        Todas las funciones vienen dentro del plan. No hay módulos que contratar aparte.
      </p>

      <button
        type='button'
        onClick={() => setExpanded((prev) => !prev)}
        aria-expanded={expanded}
        aria-controls='plan-included-features'
        className='mt-2 flex items-center gap-1 text-[12px] font-medium text-muted-foreground sm:hidden'
      >
        Ver todo ({features.length})
        <ChevronDown
          aria-hidden='true'
          data-state={expanded ? 'open' : 'closed'}
          className='h-3.5 w-3.5 transition-transform data-[state=open]:rotate-180'
        />
      </button>

      <ul
        id='plan-included-features'
        className={cn('mt-3 grid gap-1.5 sm:grid-cols-2', !expanded && 'hidden sm:grid')}
      >
        {features.map((feature) => (
          <li key={feature} className='flex items-start gap-2 text-[12.5px]'>
            <Check aria-hidden='true' className='mt-0.5 h-3.5 w-3.5 shrink-0 text-primary' />
            <span className='text-foreground'>{feature}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * Card de venta consultiva: sustituye por completo a la cotización y al botón
 * "Contratar" cuando el plan elegido no tiene precio de lista.
 */
function ContactSalesCard({
  planName,
  mailto,
  whatsapp,
}: {
  planName: string
  mailto: string | null
  whatsapp: string | null
}) {
  return (
    <div className='flex h-full flex-col'>
      <p className='text-[12px] font-semibold uppercase tracking-[0.18em] text-background/60'>
        Plan {planName}
      </p>

      <h2 className='mt-5 text-[clamp(22px,3vw,28px)] font-semibold leading-tight tracking-[-0.01em]'>
        Tu organización necesita un plan a medida
      </h2>
      <p className='mt-3 text-[13.5px] leading-relaxed text-background/70'>
        Por el número de unidades que administras armamos un precio y un acompañamiento hechos para
        ti. Escríbenos y te respondemos con una propuesta.
      </p>

      <div className='mt-auto flex flex-col gap-3 pt-6'>
        {mailto && (
          <Button
            asChild
            className='w-full rounded-full bg-background py-3 text-sm font-semibold text-foreground hover:bg-background/90'
          >
            <a href={mailto}>
              <Mail className='mr-2 h-4 w-4' aria-hidden='true' />
              Escríbenos por correo
            </a>
          </Button>
        )}
        {whatsapp && (
          <Button
            asChild
            variant='outline'
            className='w-full rounded-full border-background/30 bg-transparent py-3 text-sm font-semibold text-background hover:bg-background/10 hover:text-background'
          >
            <a href={whatsapp} target='_blank' rel='noreferrer'>
              <MessageCircle className='mr-2 h-4 w-4' aria-hidden='true' />
              Escríbenos por WhatsApp
            </a>
          </Button>
        )}
        {!mailto && !whatsapp && (
          <p className='text-[12.5px] text-background/60'>
            Contacta a nuestro equipo de ventas para recibir tu propuesta.
          </p>
        )}
      </div>
    </div>
  )
}

function IntervalButton({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type='button'
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'flex-1 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors',
        active ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'
      )}
    >
      {children}
    </button>
  )
}

function PlanOption({
  plan,
  selected,
  isCurrent,
  onSelect,
}: {
  plan: IBillingPlan
  selected: boolean
  isCurrent: boolean
  onSelect: () => void
}) {
  const band = planBandLabel(plan)

  return (
    <button
      type='button'
      onClick={onSelect}
      aria-pressed={selected}
      className={cn(
        'flex items-start justify-between gap-2 rounded-xl border px-4 py-3 text-left transition-colors',
        selected ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40'
      )}
    >
      <div className='min-w-0'>
        <div className='flex flex-wrap items-center gap-x-2 gap-y-1'>
          <p className='text-[13.5px] font-semibold text-foreground'>{plan.name}</p>
          {/* Sin esta etiqueta, quien entra a cambiar de plan ve seis tarjetas
              iguales y ninguna le dice cuál está pagando hoy: la única pista era
              que venía preseleccionada, que se pierde en cuanto toca otra. */}
          {isCurrent && (
            <span className='rounded-full bg-[var(--g)]/12 px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-[0.04em] text-[var(--g)]'>
              Tu plan actual
            </span>
          )}
        </div>
        {band && <p className='mt-0.5 text-[12px] text-muted-foreground'>{band}</p>}
      </div>
      {selected && <Check className='mt-0.5 h-4 w-4 shrink-0 text-primary' />}
    </button>
  )
}

function QuoteBreakdown({
  currency,
  periodLabel,
  isQuoting,
  quote,
  plan,
  interval,
}: {
  currency: string
  periodLabel: string
  isQuoting: boolean
  quote: IPlanQuote | null
  plan: IBillingPlan | null
  interval: 'month' | 'year'
}) {
  if (isQuoting && !quote) {
    return (
      <Skeleton name='billing-quote' loading>
        <div className='mt-5 space-y-3' aria-busy='true' aria-label='Calculando precio'>
          <div className='h-4 w-full rounded bg-background/20' />
          <div className='h-4 w-3/4 rounded bg-background/20' />
          <div className='mt-6 h-12 w-1/2 rounded bg-background/20' />
        </div>
      </Skeleton>
    )
  }

  // El precio del PLAN: el del quote (autoridad del backend) y, mientras no haya
  // cotización, el publicado en el catálogo.
  const catalogPrice = planPriceFor(plan, interval)
  const total = quote?.total ?? catalogPrice?.unit_amount ?? 0
  const planName = quote?.plan.name ?? plan?.name ?? null

  return (
    <div className={cn('mt-5 transition-opacity', isQuoting && 'opacity-60')} aria-busy={isQuoting}>
      {planName && (
        <p className='mb-4 inline-flex items-center rounded-full bg-background/10 px-3 py-1 text-[12px] font-semibold text-background'>
          Plan {planName}
        </p>
      )}

      <p className='text-[13.5px] leading-relaxed text-background/70'>
        Un solo precio con todas las funciones incluidas. Sin módulos aparte ni cargos por usuario.
      </p>

      <div className='mt-6 border-t border-background/10 pt-5'>
        <p className='flex items-end justify-between gap-3'>
          <span className='text-[13.5px] text-background/80'>
            Total <span className='text-background/50'>{periodLabel}</span>
          </span>
          <span
            className='text-[clamp(34px,4.5vw,44px)] font-semibold leading-[0.9] tracking-[-0.01em]'
            aria-live='polite'
          >
            {formatPrice(total, currency)}
          </span>
        </p>
        <p className='mt-2 text-[12px] text-background/50'>{currency.toUpperCase()}</p>
      </div>
    </div>
  )
}

function PlanBuilderSkeleton() {
  return (
    <Skeleton name='plan-builder' loading>
      <div
        className='grid gap-6 lg:grid-cols-[1.1fr_0.9fr]'
        aria-busy='true'
        aria-label='Cargando catálogo de planes'
      >
        <div className='rounded-xl border border-border bg-card p-6 sm:p-7'>
          <div className='h-3 w-48 rounded bg-muted/40' />
          <div className='mt-3 grid grid-cols-2 gap-2.5'>
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className='h-16 rounded-xl bg-muted/20' />
            ))}
          </div>
          <div className='mt-7 h-11 w-full rounded-full bg-muted/30' />
          <div className='mt-7 space-y-2.5'>
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className='h-14 w-full rounded-xl bg-muted/20' />
            ))}
          </div>
        </div>
        <div className='rounded-xl border border-border bg-foreground p-6 sm:p-7'>
          <div className='h-3 w-28 rounded bg-background/20' />
          <div className='mt-6 space-y-3'>
            <div className='h-4 w-full rounded bg-background/20' />
            <div className='h-4 w-3/4 rounded bg-background/20' />
          </div>
          <div className='mt-8 h-12 w-1/2 rounded bg-background/20' />
        </div>
      </div>
    </Skeleton>
  )
}
