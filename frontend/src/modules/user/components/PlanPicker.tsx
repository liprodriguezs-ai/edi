import { SelectableCard } from '@/shared/components/molecules/SelectableCard'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import { IncludedFeatures } from '@/modules/checkoutSubscription/components/IncludedFeatures'
import {
  planBandLabel,
  planFeatures,
  planPriceFor,
  sharedPlanFeatures,
} from '@/modules/checkoutSubscription/planSelection'
import type { BillingInterval, IBillingPlan } from '@/modules/checkoutSubscription/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Check } from 'lucide-react'

/**
 * Selector de plan del dueño: de dónde parte, a qué puede cambiar y qué trae.
 *
 * Vive fuera de `ChangeBillingPlanModal` porque el modal ya carga con el flujo de
 * catálogo + preview + confirmación; mezclar aquí la presentación lo empujaba
 * arriba del límite de 500 líneas del proyecto.
 *
 * Lo que este componente NO hace: decidir si un cambio procede. Marcar el plan que
 * "te toca" es ayuda de lectura; quien autoriza o rechaza es el backend, vía el
 * `unit_limit_warning` del preview que pinta el modal.
 */
export function PlanPicker({
  currentPlan,
  availablePlans,
  allBillablePlans,
  fittingPlan,
  selectedPlanKey,
  interval,
  currency,
  unitCount,
  onSelect,
}: {
  /** Plan vigente de la organización, para que el dueño sepa contra qué compara. */
  currentPlan: IBillingPlan | null
  /** Planes a los que puede cambiar (con precio de lista, sin el actual). */
  availablePlans: IBillingPlan[]
  /** Todos los cotizables, incluido el actual: base para las funciones comunes. */
  allBillablePlans: IBillingPlan[]
  /** Plan cuya banda cubre las unidades de la organización, si alguno lo hace. */
  fittingPlan: IBillingPlan | null
  selectedPlanKey: string
  interval: BillingInterval
  currency: string
  unitCount: number
  onSelect: (_planKey: string) => void
}) {
  // Se anuncian una sola vez cuando todos los planes traen lo mismo (modelo actual);
  // si algún plan difiere, se cae a listar las del plan seleccionado.
  const commonFeatures = sharedPlanFeatures(allBillablePlans)
  const selectedPlan = availablePlans.find((plan) => plan.key === selectedPlanKey) ?? null

  return (
    <>
      {/* Punto de partida. Sin esto el dueño elige a ciegas: no sabe cuántas
          unidades cubre lo que ya paga ni contra qué está comparando. */}
      <OrganizationContext
        currentPlan={currentPlan}
        interval={interval}
        currency={currency}
        unitCount={unitCount}
        fittingPlan={fittingPlan}
      />

      <div className='space-y-2'>
        <p className='text-[13px] font-semibold text-foreground'>
          Elige el plan al que quieres cambiar
        </p>
        <div role='radiogroup' aria-label='Plan destino' className='grid gap-2.5 sm:grid-cols-2'>
          {availablePlans.map((plan) => (
            <PlanOption
              key={plan.key}
              plan={plan}
              interval={interval}
              currency={currency}
              selected={selectedPlanKey === plan.key}
              fits={fittingPlan?.key === plan.key}
              onSelect={() => onSelect(plan.key)}
            />
          ))}
        </div>
      </div>

      {/* When every plan includes the same features, announce them ONCE: repeating the
          list per plan would suggest plans differ in features, and they only change
          band and price. */}
      <IncludedFeatures
        features={commonFeatures ?? planFeatures(selectedPlan ?? currentPlan)}
        title={commonFeatures !== null ? 'Esto viene en cualquier plan' : 'Todo lo que incluye este plan'}
        description={
          commonFeatures !== null
            ? 'Lo único que cambia entre planes es cuántas unidades cubre y el precio. No pierdes funciones al cambiar.'
            : undefined
        }
        listId='change-plan-included-features'
      />
    </>
  )
}

/** Etiqueta del periodo, en el intervalo contratado. */
function periodLabelFor(interval: BillingInterval): string {
  return interval === 'year' ? '/año' : '/mes'
}

/**
 * De dónde parte el dueño: cuántas unidades administra, qué paga hoy y qué banda
 * le corresponde por ese número.
 *
 * Es el bloque que faltaba: el selector anterior mostraba seis nombres sueltos, sin
 * decir cuántas unidades cubre el plan vigente ni contra qué se compara. Elegir
 * plan sin este dato es adivinar.
 */
function OrganizationContext({
  currentPlan,
  interval,
  currency,
  unitCount,
  fittingPlan,
}: {
  currentPlan: IBillingPlan | null
  interval: BillingInterval
  currency: string
  unitCount: number
  fittingPlan: IBillingPlan | null
}) {
  const currentPrice = planPriceFor(currentPlan, interval)
  const currentBand = currentPlan ? planBandLabel(currentPlan) : null
  const isOnFittingPlan = fittingPlan !== null && fittingPlan.key === currentPlan?.key

  return (
    <div className='rounded-none border border-border bg-muted/30 p-4'>
      <div className='flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1'>
        <div className='min-w-0'>
          <p className='text-[12px] text-muted-foreground'>Hoy pagas</p>
          <p className='text-[13.5px] font-semibold text-foreground'>
            Plan {currentPlan?.name ?? '—'}
          </p>
          {currentBand && <p className='mt-0.5 text-[12px] text-muted-foreground'>{currentBand}</p>}
        </div>
        {currentPrice && (
          <p className='text-[15px] font-semibold tabular-nums text-foreground'>
            {formatPrice(currentPrice.unit_amount, currency)}
            <span className='ml-0.5 text-[12px] font-normal text-muted-foreground'>
              {periodLabelFor(interval)}
            </span>
          </p>
        )}
      </div>

      {/* Un organización recién creado tiene 0 unidades: ahí NO hay nada que recomendar,
          y mandarlo a venta consultiva (la rama de "ninguna banda cubre ese número")
          sería alarmarlo por estar empezando. */}
      <p className='mt-3 border-t border-border pt-3 text-[12.5px] text-foreground'>
        {unitCount === 0 ? (
          <span className='text-muted-foreground'>
            Todavía no registras unidades. Cuando las cargues te decimos cuál es el plan que te
            toca.
          </span>
        ) : (
          <>
            Tu organización tiene{' '}
            <strong className='tabular-nums'>
              {unitCount} {unitCount === 1 ? 'unidad' : 'unidades'}
            </strong>
            {fittingPlan === null ? (
              <>
                {', '}más de lo que cubre el plan más grande: escríbenos y te armamos un plan a
                medida.
              </>
            ) : isOnFittingPlan ? (
              <>
                {', '}
                <Check className='mr-0.5 inline h-3.5 w-3.5 text-primary' aria-hidden />
                que es justo lo que cubre tu plan actual.
              </>
            ) : (
              <>
                {', '}y por ese número te toca el plan <strong>{fittingPlan.name}</strong>.
              </>
            )}
          </>
        )}
      </p>
    </div>
  )
}

/**
 * Una opción de plan: nombre, banda de unidades y precio en el intervalo contratado.
 *
 * El precio y la banda son lo ÚNICO que diferencia un plan de otro en este modelo,
 * así que son exactamente lo que la fila tiene que decir. Misma expresión visual que
 * la grilla del alta (`PlanBuilder`), para que cambiar de plan se vea igual que
 * contratarlo.
 */
function PlanOption({
  plan,
  interval,
  currency,
  selected,
  fits,
  onSelect,
}: {
  plan: IBillingPlan
  interval: BillingInterval
  currency: string
  selected: boolean
  fits: boolean
  onSelect: () => void
}) {
  const band = planBandLabel(plan)
  const price = planPriceFor(plan, interval)

  return (
    <SelectableCard
      role='radio'
      selected={selected}
      onSelect={onSelect}
      className='flex items-start justify-between gap-2'
    >
      <div className='min-w-0'>
        <div className='flex flex-wrap items-center gap-x-2 gap-y-1'>
          <p className='text-[13.5px] font-semibold text-foreground'>{plan.name}</p>
          {fits && (
            <StatusPill tone='b'>Te toca</StatusPill>
          )}
        </div>
        {band && <p className='mt-0.5 text-[12px] text-muted-foreground'>{band}</p>}
      </div>
      <div className='shrink-0 text-right'>
        {price ? (
          <p className='text-[13.5px] font-semibold tabular-nums text-foreground'>
            {formatPrice(price.unit_amount, currency)}
            <span className='ml-0.5 block text-[11px] font-normal text-muted-foreground'>
              {periodLabelFor(interval)}
            </span>
          </p>
        ) : (
          <span className='text-[12px] text-muted-foreground'>Sin precio de lista</span>
        )}
      </div>
    </SelectableCard>
  )
}
