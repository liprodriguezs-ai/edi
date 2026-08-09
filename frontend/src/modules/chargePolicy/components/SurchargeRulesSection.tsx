import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  SURCHARGE_MODE,
  SURCHARGE_RECURRENCES,
  type ChargePolicyFormValues,
  type SurchargeRecurrence,
} from '@/modules/chargePolicy/schema'
import { Callout } from '@/shared/components/atoms/Callout'
import type { IEntity } from '@/shared/interfaces/Entity'
import { numberOrNull } from '@/shared/utils/numberOrNull'
import { AlertTriangle, Plus, Trash2 } from 'lucide-react'
import {
  Controller,
  useFieldArray,
  useWatch,
  type Control,
  type FieldErrors,
  type UseFormRegister,
} from 'react-hook-form'

const SECTION = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const SECTION_TITLE = 'mb-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'

const RECURRENCE_LABELS: Record<SurchargeRecurrence, string> = {
  one_time: 'Una sola vez',
  per_month_overdue: 'Por cada mes de mora',
}

/** Una nueva regla en blanco — % por defecto, una sola vez. */
function emptyRule() {
  return {
    from_month: null,
    surcharge_mode_id: SURCHARGE_MODE.PERCENT,
    surcharge_value: null,
    recurrence: 'one_time' as SurchargeRecurrence,
    cap_amount: null,
  }
}

interface SurchargeRulesSectionProps {
  control: Control<ChargePolicyFormValues>
  register: UseFormRegister<ChargePolicyFormValues>
  errors: FieldErrors<ChargePolicyFormValues>
  surchargeModes: IEntity[]
  isSaving: boolean
}

interface SurchargeRuleRowProps {
  index: number
  control: Control<ChargePolicyFormValues>
  register: UseFormRegister<ChargePolicyFormValues>
  errors: FieldErrors<ChargePolicyFormValues>
  surchargeModes: IEntity[]
  isSaving: boolean
  onRemove: () => void
}

/** Fila editable de una regla de recargo. El prefijo del valor depende del modo. */
function SurchargeRuleRow({
  index,
  control,
  register,
  errors,
  surchargeModes,
  isSaving,
  onRemove,
}: SurchargeRuleRowProps) {
  const modeId = useWatch({ control, name: `surcharge_rules.${index}.surcharge_mode_id` })
  const isPercent = modeId === SURCHARGE_MODE.PERCENT
  const valuePrefix = isPercent ? '%' : '$'
  const rowErrors = errors.surcharge_rules?.[index]

  return (
    <div className='rounded-[12px] border border-border p-4'>
      <div className='mb-3 flex items-center justify-between'>
        <span className='text-[12px] font-semibold text-[var(--ink-3)]'>Recargo {index + 1}</span>
        <Button
          type='button'
          variant='ghost'
          size='icon'
          className='size-8 text-[var(--ink-3)] hover:text-[var(--r)]'
          onClick={onRemove}
          disabled={isSaving}
          aria-label={`Quitar recargo ${index + 1}`}
        >
          <Trash2 className='size-4' />
        </Button>
      </div>

      <div className='grid grid-cols-12 gap-3'>
        <Field className='col-span-12 sm:col-span-4'>
          <FieldLabel htmlFor={`surcharge-${index}-from-month`} required>
            Desde el mes
          </FieldLabel>
          <Input
            id={`surcharge-${index}-from-month`}
            type='number'
            min={1}
            max={120}
            placeholder='1'
            {...register(`surcharge_rules.${index}.from_month`, { setValueAs: numberOrNull })}
            disabled={isSaving}
          />
          <FieldDescription>Mes de morosidad en que empieza.</FieldDescription>
          <FieldError>{rowErrors?.from_month?.message}</FieldError>
        </Field>

        <Field className='col-span-12 sm:col-span-4'>
          <FieldLabel htmlFor={`surcharge-${index}-mode`} required>
            Tipo
          </FieldLabel>
          <Controller
            name={`surcharge_rules.${index}.surcharge_mode_id`}
            control={control}
            render={({ field }) => (
              <Select value={field.value || undefined} onValueChange={field.onChange} disabled={isSaving}>
                <SelectTrigger id={`surcharge-${index}-mode`} className='w-full'>
                  <SelectValue placeholder='Seleccionar…' />
                </SelectTrigger>
                <SelectContent>
                  {surchargeModes.map((mode) => (
                    <SelectItem key={mode.id} value={mode.id}>
                      {mode.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError>{rowErrors?.surcharge_mode_id?.message}</FieldError>
        </Field>

        <Field className='col-span-12 sm:col-span-4'>
          <FieldLabel htmlFor={`surcharge-${index}-value`} required>
            {isPercent ? 'Porcentaje' : 'Monto'}
          </FieldLabel>
          <div className='relative'>
            <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
              {valuePrefix}
            </span>
            <Input
              id={`surcharge-${index}-value`}
              type='number'
              step='0.01'
              min={0}
              className='pl-7'
              placeholder={isPercent ? '5' : '0.00'}
              {...register(`surcharge_rules.${index}.surcharge_value`, { setValueAs: numberOrNull })}
              disabled={isSaving}
            />
          </div>
          <FieldDescription>{isPercent ? 'Sobre el saldo en mora.' : 'Importe fijo.'}</FieldDescription>
          <FieldError>{rowErrors?.surcharge_value?.message}</FieldError>
        </Field>

        <Field className='col-span-12 sm:col-span-6'>
          <FieldLabel htmlFor={`surcharge-${index}-recurrence`} required>
            Frecuencia
          </FieldLabel>
          <Controller
            name={`surcharge_rules.${index}.recurrence`}
            control={control}
            render={({ field }) => (
              <Select value={field.value || undefined} onValueChange={field.onChange} disabled={isSaving}>
                <SelectTrigger id={`surcharge-${index}-recurrence`} className='w-full'>
                  <SelectValue placeholder='Seleccionar…' />
                </SelectTrigger>
                <SelectContent>
                  {SURCHARGE_RECURRENCES.map((recurrence) => (
                    <SelectItem key={recurrence} value={recurrence}>
                      {RECURRENCE_LABELS[recurrence]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldDescription>Cobro único o acumulado por cada mes vencido.</FieldDescription>
        </Field>

        <Field className='col-span-12 sm:col-span-6'>
          <FieldLabel htmlFor={`surcharge-${index}-cap`}>Tope (opcional)</FieldLabel>
          <div className='relative'>
            <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
              $
            </span>
            <Input
              id={`surcharge-${index}-cap`}
              type='number'
              step='0.01'
              min={0}
              className='pl-7'
              placeholder='Sin tope'
              {...register(`surcharge_rules.${index}.cap_amount`, { setValueAs: numberOrNull })}
              disabled={isSaving}
            />
          </div>
          <FieldDescription>Monto máximo del recargo acumulado.</FieldDescription>
          <FieldError>{rowErrors?.cap_amount?.message}</FieldError>
        </Field>
      </div>
    </div>
  )
}

/**
 * Sección editable del calendario de recargos moratorios de la regla. Cada regla
 * escala desde un mes de morosidad. Los valores se capturan en unidades visibles
 * (% o pesos) y se convierten a basis points / minor units al enviar.
 */
export function SurchargeRulesSection({
  control,
  register,
  errors,
  surchargeModes,
  isSaving,
}: SurchargeRulesSectionProps) {
  const { fields, append, remove } = useFieldArray({ control, name: 'surcharge_rules' })

  return (
    <section className={SECTION}>
      <h3 className={SECTION_TITLE}>Recargo moratorio</h3>
      <p className='mb-4 text-[12.5px] text-[var(--ink-3)]'>
        Penalización opcional cuando un cargo entra en mora.
      </p>

      <Callout icon={AlertTriangle} className='mb-4'>
        Cada recargo aplica desde el mes de morosidad que indiques. Si hay varios, se usa el de
        mayor mes alcanzado. Sin reglas, no se cobran recargos.
      </Callout>

      {fields.length > 0 && (
        <div className='space-y-3'>
          {fields.map((field, index) => (
            <SurchargeRuleRow
              key={field.id}
              index={index}
              control={control}
              register={register}
              errors={errors}
              surchargeModes={surchargeModes}
              isSaving={isSaving}
              onRemove={() => remove(index)}
            />
          ))}
        </div>
      )}

      <Button
        type='button'
        variant='outline'
        className='mt-4 rounded-full'
        onClick={() => append(emptyRule())}
        disabled={isSaving}
      >
        <Plus className='size-4' />
        Agregar recargo
      </Button>
    </section>
  )
}
