import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { AMOUNT_MODE, type ChargePolicyFormValues } from '@/modules/chargePolicy/schema'
import { numberOrNull } from '@/shared/utils/numberOrNull'
import type { FieldErrors, UseFormRegister } from 'react-hook-form'

export type MoneyFieldName =
  | 'fixed_amount'
  | 'min_amount'
  | 'ideal_amount'
  | 'max_amount'
  | 'prorate_total_amount'

export interface AmountModeFlags {
  isFixed: boolean
  isRange: boolean
  isByShare: boolean
}

/** Deriva qué campos de monto mostrar según el modo seleccionado. */
export function resolveAmountFlags(amountModeId: string): AmountModeFlags {
  return {
    isFixed:
      amountModeId === AMOUNT_MODE.FIXED_REQUIRED || amountModeId === AMOUNT_MODE.FIXED_OPTIONAL,
    isRange: amountModeId === AMOUNT_MODE.RANGE,
    isByShare: amountModeId === AMOUNT_MODE.BY_SHARE,
  }
}

interface MoneyInputFieldProps {
  name: MoneyFieldName
  label: string
  span?: string
  isSaving: boolean
  register: UseFormRegister<ChargePolicyFormValues>
  errors: FieldErrors<ChargePolicyFormValues>
}

/** Input de dinero en pesos con prefijo $ — extraído del form. */
function MoneyInputField({
  name,
  label,
  span = 'md:col-span-4',
  isSaving,
  register,
  errors,
}: MoneyInputFieldProps) {
  return (
    <Field className={`col-span-12 ${span}`}>
      <FieldLabel htmlFor={`policy-${name}`} required>
        {label}
      </FieldLabel>
      <div className='relative'>
        <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
          $
        </span>
        <Input
          id={`policy-${name}`}
          type='number'
          step='0.01'
          min={0}
          className='pl-7'
          placeholder='0.00'
          {...register(name, { setValueAs: numberOrNull })}
          disabled={isSaving}
        />
      </div>
      <FieldError>{errors[name]?.message}</FieldError>
    </Field>
  )
}

type AmountModeMoneyFieldsProps = Omit<MoneyInputFieldProps, 'name' | 'label'> & AmountModeFlags

/** Campos de monto condicionales según el modo — extraído del form. */
function AmountModeMoneyFields({
  isFixed,
  isRange,
  isByShare,
  ...moneyProps
}: AmountModeMoneyFieldsProps) {
  if (isFixed) {
    return (
      <MoneyInputField
        {...moneyProps}
        name='fixed_amount'
        label='Monto fijo'
        span='md:col-span-4'
      />
    )
  }
  if (isRange) {
    return (
      <>
        <MoneyInputField {...moneyProps} name='min_amount' label='Mínimo' />
        <MoneyInputField {...moneyProps} name='ideal_amount' label='Ideal' />
        <MoneyInputField {...moneyProps} name='max_amount' label='Máximo' />
      </>
    )
  }
  if (isByShare) {
    return (
      <MoneyInputField
        {...moneyProps}
        name='prorate_total_amount'
        label='Monto total a prorratear'
        span='md:col-span-6'
      />
    )
  }
  return null
}

interface AmountModeSelectorProps {
  register: UseFormRegister<ChargePolicyFormValues>
  errors: FieldErrors<ChargePolicyFormValues>
  flags: AmountModeFlags
  isSaving: boolean
}

/**
 * Campos de monto dependientes del modo (fijo / rango / por indiviso). El SELECTOR de
 * modo (RadioGroup de tarjetas) se OCULTÓ del form por decisión de producto: solo se
 * ofrece "Fijo obligatorio", así que `amount_mode_id` queda fijo en `AMOUNT_MODE.FIXED_REQUIRED`
 * (default en `buildPolicyDefaults`) y nunca se le pide al usuario que elija. Las ramas
 * `isRange`/`isByShare` de `AmountModeMoneyFields` SIGUEN VIVAS como red de seguridad: una
 * política legacy creada en modo Rango o Por indiviso aún hidrata y edita sus montos
 * correctamente, aunque el form ya no ofrezca crear una nueva regla en esos modos.
 */
export function AmountModeSelector({ register, errors, flags, isSaving }: AmountModeSelectorProps) {
  return (
    <div className='col-span-12'>
      <div className='grid grid-cols-12 gap-4'>
        <AmountModeMoneyFields {...flags} isSaving={isSaving} register={register} errors={errors} />
      </div>
    </div>
  )
}
