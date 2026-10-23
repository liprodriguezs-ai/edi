import { cn } from '@/lib/utils'
import { BILLING_INTERVAL_OPTIONS } from '@/modules/checkoutSubscription/components/billingIntervalOptions'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import { useBillingQuoter } from '@/modules/checkoutSubscription/hooks/useBillingQuoter'
import type { BillingInterval } from '@/modules/tenant/billing/schema'
import { SegmentedControl } from '@/shared/components/molecules/SegmentedControl'
import { SelectableCard } from '@/shared/components/molecules/SelectableCard'
import { Skeleton } from 'boneyard-js/react'
import { useEffect } from 'react'

export interface IWizardPlanSelection {
  /** Plan elegido (basico..premium / a_medida), o null mientras carga el catálogo. */
  planKey: string | null
  interval: BillingInterval
  /** Total en minor units (centavos) que cobrará el backend, o null si aún no cotiza. */
  total: number | null
  /** true cuando el plan elegido es de venta consultiva: no se puede cobrar. */
  isContactSales: boolean
}

interface WizardPlanSelectorProps {
  /** true = pago manual (muestra el precio a cobrar); false = cortesía (sin costo). */
  showPrice: boolean
  onChange: (_selection: IWizardPlanSelection) => void
}

/**
 * Armador compacto de plan para el wizard de alta. Reutiliza `useBillingQuoter`
 * (catálogo público + cotización en vivo, autoridad del backend). Sólo se monta
 * cuando el modo lo necesita (cortesía / pago manual), así el catálogo carga tarde.
 * Reporta la selección {plan_key, interval} al form padre; NO hace checkout.
 *
 * Ya no hay selección de módulos (todo va incluido en el plan) ni captura de
 * unidades: la banda vive en la propia tarjeta del plan.
 */
export function WizardPlanSelector({ showPrice, onChange }: WizardPlanSelectorProps) {
  const {
    quote,
    plans,
    isLoadingCatalog,
    catalogError,
    billingInterval,
    setBillingInterval,
    selectedPlanKey,
    selectedPlan,
    selectPlan,
    isContactSales,
    isQuoting,
    quoteError,
    retryQuote,
  } = useBillingQuoter()

  const currency = quote?.currency ?? 'MXN'
  const total = quote?.total ?? null

  useEffect(() => {
    onChange({
      planKey: selectedPlanKey,
      interval: billingInterval,
      total,
      isContactSales,
    })
  }, [selectedPlanKey, billingInterval, total, isContactSales, onChange])

  if (isLoadingCatalog) {
    return (
      <Skeleton name='wizard-plan' loading>
        <div
          className='space-y-4'
          aria-busy='true'
          aria-label='Cargando catálogo'
        >
          <div className='h-10 w-24 rounded-none bg-muted/40' />
          <div className='h-2 w-full rounded-none bg-muted/40' />
          <div className='h-11 w-full rounded-none bg-muted/30' />
        </div>
      </Skeleton>
    )
  }

  if (catalogError) {
    return (
      <div
        role='alert'
        className='rounded-none border border-destructive/20 bg-destructive/5 p-4 text-sm text-destructive'
      >
        {catalogError}
      </div>
    )
  }

  return (
    <div className='space-y-5'>
      {/* Plan: único control. Cada tarjeta muestra su banda de unidades. */}
      <div>
        <p className='text-sm font-medium text-foreground'>Plan</p>
        <p className='mt-0.5 text-xs text-muted-foreground'>
          Elige el plan según cuántas unidades tiene la organización.
        </p>
        <div className='mt-2 grid gap-2 sm:grid-cols-2'>
          {plans.map((plan) => {
            const isActive = selectedPlanKey === plan.key
            const band = bandLabel(plan.suggested_unit_min, plan.suggested_unit_max)
            return (
              <SelectableCard
                key={plan.key}
                selected={isActive}
                onSelect={() => selectPlan(plan.key)}
                className='px-3 py-2.5'
              >
                <p className='text-sm font-semibold text-foreground'>{plan.name}</p>
                {band && <p className='mt-0.5 text-xs text-muted-foreground'>{band}</p>}
              </SelectableCard>
            )
          })}
        </div>
      </div>

      {/* Intervalo: sin precio de lista el intervalo no aplica */}
      {!isContactSales && (
        <fieldset>
          <legend className='text-sm font-medium text-foreground'>Facturación</legend>
          <SegmentedControl
            value={billingInterval}
            onValueChange={setBillingInterval}
            options={BILLING_INTERVAL_OPTIONS}
            aria-label='Intervalo de facturación'
            size='sm'
            className='mt-2'
          />
        </fieldset>
      )}

      {/* Precio en vivo / aviso de plan a medida */}
      <div className='rounded-none border bg-background p-3'>
        {isContactSales ? (
          <p className='text-sm text-muted-foreground'>
            El plan{' '}
            <span className='font-medium text-foreground'>{selectedPlan?.name ?? 'A medida'}</span>{' '}
            no tiene precio de lista: se cotiza con el equipo comercial. Solo puedes darlo de alta
            como cortesía.
          </p>
        ) : showPrice ? (
          quoteError ? (
            <div className='flex items-center justify-between gap-3' role='alert'>
              <span className='text-sm text-destructive'>No pudimos calcular el monto.</span>
              <button
                type='button'
                onClick={retryQuote}
                className='rounded-none border border-destructive/30 px-3 py-1 text-sm font-semibold text-destructive transition-colors hover:bg-destructive/5'
              >
                Reintentar
              </button>
            </div>
          ) : (
            <div className='flex items-end justify-between gap-3' aria-live='polite'>
              <span className='text-sm text-muted-foreground'>
                Monto a cobrar{' '}
                <span className='text-muted-foreground/70'>
                  / {billingInterval === 'year' ? 'año' : 'mes'}
                </span>
                {selectedPlan && (
                  <span className='text-muted-foreground/70'> · Plan {selectedPlan.name}</span>
                )}
              </span>
              <span
                className={cn(
                  'font-mono text-2xl font-semibold tabular-nums',
                  isQuoting && 'opacity-60'
                )}
              >
                {formatPrice(total ?? 0, currency)}
              </span>
            </div>
          )
        ) : (
          <p className='text-sm font-medium text-primary'>
            Sin costo — acceso de cortesía permanente.
          </p>
        )}
      </div>
    </div>
  )
}

/** Texto de la banda de unidades del plan (el plan a medida no tiene tope). */
function bandLabel(min: number | null, max: number | null): string | null {
  if (min === null) return null
  if (max === null) return `Desde ${min} unidades`
  return `Ideal para ${min}–${max} unidades`
}
