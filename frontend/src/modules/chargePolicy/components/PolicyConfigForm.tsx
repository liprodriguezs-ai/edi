import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  AmountModeSelector,
  type AmountModeFlags,
} from '@/modules/chargePolicy/components/AmountModeSelector'
import { ChargeConceptSelect } from '@/modules/chargePolicy/components/ChargeConceptSelect'
import { PeriodicitySelector } from '@/modules/chargePolicy/components/PeriodicitySelector'
import { PERIODICITY, type ChargePolicyFormValues } from '@/modules/chargePolicy/schema'
import { numberOrNull } from '@/shared/utils/numberOrNull'
import type { Control, FieldErrors, UseFormRegister } from 'react-hook-form'
import { Controller } from 'react-hook-form'

const SECTION = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const SECTION_TITLE =
  'mb-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'

interface PolicyConfigFormProps {
  control: Control<ChargePolicyFormValues>
  register: UseFormRegister<ChargePolicyFormValues>
  errors: FieldErrors<ChargePolicyFormValues>
  amountFlags: AmountModeFlags
  /** La periodicidad determina el modo de cobro (eventual = directo; resto = periódico). */
  periodicityId: string
  /** true cuando el concepto no viene fijo (alta sin conceptId): se elige en el form. */
  needsConceptPicker: boolean
  /** Valor del clúster para los días "por vencer" — se usa como placeholder del override. */
  dueSoonDaysPlaceholder: number
  /**
   * Valor del clúster para los días de gracia — se usa como placeholder del override.
   * undefined cuando el clúster no tiene el valor configurado (placeholder vacío, sin
   * número inventado).
   */
  graceDaysPlaceholder?: number
  isSaving: boolean
  /** true cuando la regla ya facturó (has_billed): bloquea el selector de periodicidad. */
  hasBilled: boolean
}

/**
 * Configuración EDITABLE de la regla de cobro. Reusa AmountModeSelector +
 * PeriodicitySelector. Contiene Identidad, Periodicidad, Monto + Gracia y Vigencia.
 *
 * El "modo de cobro" NO es un campo: lo determina la periodicidad. Una periodicidad
 * EVENTUAL es un cobro único (directo) → no se muestra Vigencia (las fechas del cobro
 * van en ChargeRuleFormPage). Cualquier otra periodicidad es recurrente → se muestra
 * la Vigencia (valid_from/valid_to) que usa el cron.
 */
export function PolicyConfigForm({
  control,
  register,
  errors,
  amountFlags,
  periodicityId,
  needsConceptPicker,
  dueSoonDaysPlaceholder,
  graceDaysPlaceholder,
  isSaving,
  hasBilled,
}: PolicyConfigFormProps) {
  const isEventual = periodicityId === PERIODICITY.EVENTUAL

  return (
    <div className='space-y-5'>
      {/* Concepto: solo cuando no viene fijo por la URL/edición. Es la 1ª decisión. */}
      {needsConceptPicker && (
        <section className={SECTION}>
          <h3 className={SECTION_TITLE}>Concepto</h3>
          <Field>
            <FieldLabel htmlFor='policy-concept' required>
              Concepto de cobro
            </FieldLabel>
            <Controller
              name='charge_concept_id'
              control={control}
              render={({ field }) => (
                <ChargeConceptSelect
                  id='policy-concept'
                  value={field.value}
                  onChange={field.onChange}
                  disabled={isSaving}
                />
              )}
            />
            <FieldDescription>A qué cobrable pertenece esta programación.</FieldDescription>
            <FieldError>{errors.charge_concept_id?.message}</FieldError>
          </Field>
        </section>
      )}

      {/* Identidad de la regla — nombre + estado en una sola fila para aprovechar el ancho. */}
      <section className={SECTION}>
        <h3 className={SECTION_TITLE}>Identidad</h3>
        <div className='grid grid-cols-12 gap-4'>
          <Field className='col-span-12 md:col-span-8'>
            <FieldLabel htmlFor='policy-name' required>
              Nombre de la programación
            </FieldLabel>
            <Input
              id='policy-name'
              placeholder='Mantenimiento mensual 2026'
              {...register('name')}
              disabled={isSaving}
            />
            <FieldError>{errors.name?.message}</FieldError>
          </Field>

          <Field className='col-span-12 md:col-span-4'>
            <FieldLabel htmlFor='policy-active'>Activa</FieldLabel>
            <Controller
              name='is_active'
              control={control}
              render={({ field }) => (
                <Switch
                  id='policy-active'
                  checked={field.value}
                  onCheckedChange={field.onChange}
                  disabled={isSaving}
                />
              )}
            />
            <FieldDescription>Se puede usar para generar cobros</FieldDescription>
          </Field>
        </div>
      </section>

      {/* Periodicidad */}
      <section className={SECTION}>
        <h3 className={SECTION_TITLE}>Periodicidad</h3>
        <div className='grid grid-cols-12 gap-4'>
          <PeriodicitySelector
            control={control}
            errors={errors}
            isSaving={isSaving}
            disabled={hasBilled}
          />
        </div>
      </section>

      {/* Monto + Gracia */}
      <section className={SECTION}>
        <h3 className={SECTION_TITLE}>Monto</h3>
        <div className='grid grid-cols-12 gap-4'>
          <AmountModeSelector
            register={register}
            errors={errors}
            flags={amountFlags}
            isSaving={isSaving}
          />
        </div>
        <div className='mt-4 grid grid-cols-12 gap-4'>
          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel htmlFor='policy-grace'>Días de gracia</FieldLabel>
            <Input
              id='policy-grace'
              type='number'
              min={0}
              max={365}
              placeholder={graceDaysPlaceholder != null ? String(graceDaysPlaceholder) : ''}
              {...register('grace_days', { setValueAs: numberOrNull })}
              disabled={isSaving}
            />
            <FieldDescription>
              Días después del vencimiento para marcar el cargo como moroso.
              {graceDaysPlaceholder != null &&
                ` Vacío usa el del condominio (${graceDaysPlaceholder}).`}
            </FieldDescription>
            <FieldError>{errors.grace_days?.message}</FieldError>
          </Field>

          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel htmlFor='policy-due-soon'>Días para «por vencer»</FieldLabel>
            <Input
              id='policy-due-soon'
              type='number'
              min={0}
              max={60}
              placeholder={String(dueSoonDaysPlaceholder)}
              {...register('due_soon_days', { setValueAs: numberOrNull })}
              disabled={isSaving}
            />
            <FieldDescription>
              Días antes del vencimiento para avisar. Vacío usa el del condominio (
              {dueSoonDaysPlaceholder}).
            </FieldDescription>
            <FieldError>{errors.due_soon_days?.message}</FieldError>
          </Field>
        </div>
      </section>

      {/* Vigencia (solo recurrente). En eventual (cobro único) NO se muestra: las
          fechas del cobro (generación/vencimiento) viven en ChargeRuleFormPage. */}
      {!isEventual && (
        <section className={SECTION}>
          <h3 className={SECTION_TITLE}>Vigencia</h3>
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-6'>
              <FieldLabel htmlFor='policy-from' required>
                Vigente desde
              </FieldLabel>
              <Input id='policy-from' type='date' {...register('valid_from')} disabled={isSaving} />
              <FieldError>{errors.valid_from?.message}</FieldError>
            </Field>
            <Field className='col-span-6'>
              <FieldLabel htmlFor='policy-to' required>
                Hasta
              </FieldLabel>
              <Input id='policy-to' type='date' {...register('valid_to')} disabled={isSaving} />
              <FieldError>{errors.valid_to?.message}</FieldError>
            </Field>
          </div>
        </section>
      )}
    </div>
  )
}
