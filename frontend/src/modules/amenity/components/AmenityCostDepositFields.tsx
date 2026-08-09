import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import type { AmenityFormValues } from '@/modules/amenity/schema'
import { SearchSelect } from '@/shared/components/SearchSelect'
import type { ICatalogItem } from '@/shared/hooks/useCatalogs'
import { Controller, type Control, type FieldErrors } from 'react-hook-form'

export interface AmenityCostDepositFieldsProps {
  control: Control<AmenityFormValues>
  errors: FieldErrors<AmenityFormValues>
  hasCost: boolean
  requiresDeposit: boolean
  bankAccountOptions: ICatalogItem[]
  disabled: boolean
  /** true mientras `useCatalogs` todavía no resolvió las opciones (ver AmenityForm). */
  catalogsLoading: boolean
}

/**
 * FieldSet "Costo y depósito" del `AmenityForm`, extraído para mantener el
 * archivo bajo el límite de líneas de ESLint. Sin cambios de comportamiento:
 * mismos campos, misma validación (vive en `amenityFormSchema`) y misma
 * visibilidad condicional (`hasCost`/`requiresDeposit`).
 */
export function AmenityCostDepositFields({
  control,
  errors,
  hasCost,
  requiresDeposit,
  bankAccountOptions,
  disabled,
  catalogsLoading,
}: AmenityCostDepositFieldsProps) {
  return (
    <FieldSet>
      <FieldLegend>Costo y depósito</FieldLegend>
      <FieldGroup>
        <div className='grid grid-cols-12 gap-4'>
          <Field className='col-span-12 md:col-span-3'>
            <FieldLabel htmlFor='amenity-has-cost'>Tiene costo</FieldLabel>
            <Controller
              name='has_cost'
              control={control}
              render={({ field }) => (
                <Switch
                  id='amenity-has-cost'
                  checked={field.value}
                  onCheckedChange={field.onChange}
                  disabled={disabled}
                />
              )}
            />
          </Field>

          {hasCost && (
            <>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='amenity-cost-amount' required>
                  Monto del costo
                </FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Controller
                    name='cost_amount'
                    control={control}
                    render={({ field }) => (
                      <Input
                        id='amenity-cost-amount'
                        type='number'
                        step='0.01'
                        min={0}
                        className='pl-7'
                        value={field.value ?? ''}
                        onChange={(e) =>
                          field.onChange(e.target.value === '' ? null : Number(e.target.value))
                        }
                        disabled={disabled}
                      />
                    )}
                  />
                </div>
                <FieldError>{errors.cost_amount?.message}</FieldError>
              </Field>

              <Field className='col-span-12 md:col-span-5'>
                <FieldLabel htmlFor='amenity-bank-account' required>
                  Cuenta destino
                </FieldLabel>
                <Controller
                  name='bank_account_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='amenity-bank-account'
                      // BUG FIX (E2E QA round 2): withhold the value until catalogs
                      // load — see the same fix on "Modo de reservación" in AmenityForm.
                      value={catalogsLoading ? null : field.value || null}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={bankAccountOptions}
                      isDisabled={disabled || catalogsLoading}
                      isLoading={catalogsLoading}
                      invalid={!!errors.bank_account_id}
                      isClearable
                      placeholder={catalogsLoading ? 'Cargando…' : 'Seleccionar cuenta…'}
                    />
                  )}
                />
                <FieldError>{errors.bank_account_id?.message}</FieldError>
              </Field>
            </>
          )}

          <Field className='col-span-12 md:col-span-3'>
            <FieldLabel htmlFor='amenity-requires-deposit'>Requiere depósito</FieldLabel>
            <Controller
              name='requires_deposit'
              control={control}
              render={({ field }) => (
                <Switch
                  id='amenity-requires-deposit'
                  checked={field.value}
                  onCheckedChange={field.onChange}
                  disabled={disabled}
                />
              )}
            />
          </Field>

          {requiresDeposit && (
            <>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='amenity-deposit-amount' required>
                  Monto del depósito
                </FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Controller
                    name='deposit_amount'
                    control={control}
                    render={({ field }) => (
                      <Input
                        id='amenity-deposit-amount'
                        type='number'
                        step='0.01'
                        min={0}
                        className='pl-7'
                        value={field.value ?? ''}
                        onChange={(e) =>
                          field.onChange(e.target.value === '' ? null : Number(e.target.value))
                        }
                        disabled={disabled}
                      />
                    )}
                  />
                </div>
                <FieldError>{errors.deposit_amount?.message}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='amenity-deposit-due-days' required>
                  Días para liquidar
                </FieldLabel>
                <Controller
                  name='deposit_due_days'
                  control={control}
                  render={({ field }) => (
                    <Input
                      id='amenity-deposit-due-days'
                      type='number'
                      min={0}
                      value={field.value ?? ''}
                      onChange={(e) =>
                        field.onChange(e.target.value === '' ? null : Number(e.target.value))
                      }
                      disabled={disabled}
                    />
                  )}
                />
                <FieldError>{errors.deposit_due_days?.message}</FieldError>
              </Field>
            </>
          )}
        </div>
      </FieldGroup>
    </FieldSet>
  )
}
