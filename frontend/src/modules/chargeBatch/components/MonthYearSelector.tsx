import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { MONTH_NAMES } from '@/modules/chargeBatch/constants'
import type { ChargeBatchFormValues } from '@/modules/chargeBatch/schema'
import { Controller, type Control, type UseFormRegister, type FieldErrors } from 'react-hook-form'

export { MONTH_NAMES }

interface MonthYearSelectorProps {
  control: Control<ChargeBatchFormValues>
  register: UseFormRegister<ChargeBatchFormValues>
  errors: FieldErrors<ChargeBatchFormValues>
  disabled?: boolean
}

/** Selector de año y mes para el formulario de lote de cobro. */
export function MonthYearSelector({ control, register, errors, disabled }: MonthYearSelectorProps) {
  return (
    <>
      <Field className='col-span-6 md:col-span-3'>
        <FieldLabel htmlFor='batch-year' required>
          Año
        </FieldLabel>
        <Input
          id='batch-year'
          type='number'
          min={2000}
          max={2100}
          {...register('year', { setValueAs: (v) => Number(v) })}
          disabled={disabled}
        />
        <FieldError>{errors.year?.message}</FieldError>
      </Field>
      <Field className='col-span-6 md:col-span-3'>
        <FieldLabel htmlFor='batch-month'>Mes</FieldLabel>
        <Controller
          name='month'
          control={control}
          render={({ field }) => (
            <Select
              value={field.value != null ? String(field.value) : 'none'}
              onValueChange={(v) => field.onChange(v === 'none' ? null : Number(v))}
              disabled={disabled}
            >
              <SelectTrigger id='batch-month' className='w-full'>
                <SelectValue placeholder='Sin mes' />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value='none'>Sin mes (eventual)</SelectItem>
                {MONTH_NAMES.map((monthName, index) => (
                  <SelectItem key={monthName} value={String(index + 1)}>
                    {monthName}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
      </Field>
    </>
  )
}
