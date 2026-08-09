import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { PERIODICITY, type ChargePolicyFormValues } from '@/modules/chargePolicy/schema'
import type { Control, FieldErrors } from 'react-hook-form'
import { Controller } from 'react-hook-form'

interface PeriodicityCard {
  id: string
  title: string
  /** Cadencia en lenguaje llano. El detalle "nace/vence" vive en la línea de tiempo. */
  cut: string
  /**
   * Oculta del selector: la periodicidad sigue existiendo en el catálogo central,
   * solo no se ofrece en el form. Re-activar = quitar `hidden`.
   */
  hidden?: boolean
}

/** Tarjetas estáticas keyed por PERIODICITY UUID — no se carga el catálogo. */
const PERIODICITY_CARDS: PeriodicityCard[] = [
  {
    id: PERIODICITY.MONTHLY,
    title: 'Mensual',
    cut: 'Cada mes',
  },
  {
    id: PERIODICITY.BIWEEKLY,
    title: 'Quincenal',
    cut: 'Cada quincena',
    // Oculta: simplificación temporal.
    hidden: true,
  },
  {
    id: PERIODICITY.WEEKLY,
    title: 'Semanal',
    cut: 'Cada semana',
    // Oculta: CatalogSeeder dejó de sembrar la fila, así que en una base nueva
    // elegirla falla contra Rule::exists al guardar la política.
    hidden: true,
  },
  {
    id: PERIODICITY.BIMONTHLY,
    title: 'Bimestral',
    cut: 'Cada 2 meses',
    // Oculta: simplificación temporal.
    hidden: true,
  },
  {
    id: PERIODICITY.YEARLY,
    title: 'Anual',
    cut: 'Cada año',
  },
  {
    id: PERIODICITY.EVENTUAL,
    title: 'Eventual',
    cut: 'Manual — tú defines las fechas',
  },
  {
    id: PERIODICITY.EVERY_TWO_DAYS,
    title: 'Cada 2 días (pruebas)',
    cut: 'Ciclo comprimido para QA — cada periodo dura 2 días',
  },
]

interface PeriodicitySelectorProps {
  control: Control<ChargePolicyFormValues>
  errors: FieldErrors<ChargePolicyFormValues>
  isSaving: boolean
  /** true cuando la regla ya facturó (has_billed): la periodicidad queda bloqueada. */
  disabled?: boolean
}

/**
 * Selector de periodicidad con tarjetas que explican el corte del período de cada
 * opción. Reemplaza al `<Select>` plano. Las tarjetas son estáticas (UUIDs).
 */
export function PeriodicitySelector({
  control,
  errors,
  isSaving,
  disabled,
}: PeriodicitySelectorProps) {
  return (
    <div className='col-span-12'>
      <Field>
        <FieldLabel id='periodicity-label' required>
          Periodicidad
        </FieldLabel>
        <Controller
          name='periodicity_id'
          control={control}
          render={({ field }) => (
            <RadioGroup
              aria-labelledby='periodicity-label'
              value={field.value ?? ''}
              onValueChange={field.onChange}
              disabled={isSaving || disabled}
              className='grid grid-cols-1 gap-2 sm:grid-cols-2 2xl:grid-cols-3'
            >
              {PERIODICITY_CARDS.filter((card) => !card.hidden).map((card) => {
                const isSelected = field.value === card.id
                return (
                  <label
                    key={card.id}
                    className={`flex cursor-pointer gap-2.5 rounded-[12px] border p-3 transition-colors ${
                      isSelected
                        ? 'border-[var(--green)] bg-[var(--green-bg)]'
                        : 'border-border hover:border-[var(--green)]/40'
                    }`}
                  >
                    <RadioGroupItem value={card.id} className='mt-0.5' />
                    <span className='min-w-0'>
                      <span className='block text-[13px] font-semibold text-foreground'>
                        {card.title}
                      </span>
                      <span className='mt-0.5 block text-[12px] leading-snug text-[var(--ink-3)]'>
                        {card.cut}
                      </span>
                    </span>
                  </label>
                )
              })}
            </RadioGroup>
          )}
        />
        <FieldError>{errors.periodicity_id?.message}</FieldError>
      </Field>
    </div>
  )
}
