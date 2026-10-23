import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import type { BillingPaymentMethod } from '@/modules/tenant/billing/schema'
import {
  WizardPlanSelector,
  type IWizardPlanSelection,
} from '@/modules/tenant/components/WizardPlanSelector'
import {
  PROVISION_MODE_CONFIG,
  tenantProvisionFormSchema,
  type IProvisionTenantRequest,
  type ITenant,
  type TenantProvisionFormValues,
  type TenantProvisionMode,
} from '@/modules/tenant/schema'
import { TenantResource } from '@/modules/tenant/services/TenantResource'
import { useTenantStore } from '@/modules/tenant/store/tenantStore'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { TimeZoneSelect } from '@/shared/components/TimeZoneSelect'
import { DEFAULT_TENANT_TIME_ZONE } from '@/shared/constants/timeZones'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useCallback, useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

const PROVISION_MODES: TenantProvisionMode[] = ['trial', 'courtesy', 'manual_paid', 'card']

const PAYMENT_METHOD_LABELS: Record<BillingPaymentMethod, string> = {
  card: 'Tarjeta (Stripe)',
  transfer: 'Transferencia',
  cash: 'Efectivo',
}

/** Métodos de pago permitidos según el modo de provisión. */
function allowedMethods(mode: TenantProvisionMode): BillingPaymentMethod[] {
  if (mode === 'card') return ['card']
  if (mode === 'manual_paid') return ['transfer', 'cash']
  return ['card', 'transfer', 'cash']
}

interface TenantProvisionFormProps {
  formId?: string
  onSuccess?: (_tenant: ITenant) => void
  /**
   * Reporta al wizard si la selección actual se puede enviar. Hoy sólo se bloquea
   * la combinación "plan a medida + pago manual": ese plan no tiene precio de lista,
   * así que el backend la rechaza con 422 y el botón no debe quedar habilitado.
   */
  onCanSubmitChange?: (_canSubmit: boolean) => void
}

/**
 * Alta de tenant con facturación integrada (super-admin, paso 1 del wizard).
 * Reemplaza el select legacy de `subscription_plan`: elige modo de provisión,
 * método de pago y (para cortesía/pago manual) el armador de plan con cotización
 * en vivo. El monto SIEMPRE lo calcula el backend (POST /tenant/provision).
 */
export const TenantProvisionForm = ({
  formId,
  onSuccess,
  onCanSubmitChange,
}: TenantProvisionFormProps) => {
  const isSaving = useTenantStore((s) => s.isSaving)
  const putSaving = useTenantStore((s) => s.putSaving)

  const [planSelection, setPlanSelection] = useState<IWizardPlanSelection | null>(null)

  const {
    register,
    handleSubmit,
    control,
    watch,
    setValue,
    formState: { errors },
  } = useForm<TenantProvisionFormValues>({
    resolver: zodResolver(tenantProvisionFormSchema),
    defaultValues: {
      name: '',
      timezone: DEFAULT_TENANT_TIME_ZONE,
      provision_mode: 'trial',
      payment_method: 'card',
      notes: '',
    },
  })

  const provisionMode = watch('provision_mode')
  const paymentMethod = watch('payment_method')
  const needsPlan = provisionMode === 'courtesy' || provisionMode === 'manual_paid'

  // Al cambiar de modo, corrige el método de pago si el actual no aplica
  // (card obligado en modo tarjeta; transferencia/efectivo en pago manual).
  useEffect(() => {
    const methods = allowedMethods(provisionMode)
    if (!methods.includes(paymentMethod)) {
      setValue('payment_method', methods[0], { shouldValidate: true })
    }
  }, [provisionMode, paymentMethod, setValue])

  const handlePlanChange = useCallback((selection: IWizardPlanSelection) => {
    setPlanSelection(selection)
  }, [])

  const methods = allowedMethods(provisionMode)

  /**
   * El plan a medida no tiene precio de lista: sólo se puede dar de alta como
   * cortesía. Con pago manual el backend responde 422, así que se bloquea el envío
   * en la UI en vez de dejar que el operador choque contra el error.
   */
  const isContactSalesOnPaidMode =
    needsPlan && provisionMode === 'manual_paid' && planSelection?.isContactSales === true

  useEffect(() => {
    onCanSubmitChange?.(!isContactSalesOnPaidMode)
  }, [isContactSalesOnPaidMode, onCanSubmitChange])

  const onSubmit = handleSubmit(async (values) => {
    const payload: IProvisionTenantRequest = {
      name: values.name.trim(),
      timezone: values.timezone,
      payment_method: values.payment_method,
      provision_mode: values.provision_mode,
      notes: values.notes?.trim() || undefined,
    }

    if (needsPlan) {
      if (!planSelection || !planSelection.planKey) {
        toast.error(
          'Selecciona un plan',
          'Espera a que cargue el catálogo y elige el plan de la organización.'
        )
        return
      }
      // Un plan a medida no tiene precio de lista: sólo puede darse de alta como cortesía.
      if (planSelection.isContactSales && values.provision_mode !== 'courtesy') {
        toast.error(
          'Plan sin precio de lista',
          'El plan a medida se cotiza con el equipo comercial. Da de alta la organización como cortesía y registra el cobro después.'
        )
        return
      }
      payload.plan_key = planSelection.planKey
    }

    if (values.provision_mode === 'manual_paid') {
      payload.interval = planSelection?.interval
    }

    try {
      putSaving(true)
      const tenant = await TenantResource.provision(payload)
      toast.success('Organización creado', 'La facturación quedó provisionada.')
      onSuccess?.(tenant)
    } catch (error) {
      const message =
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : { title: 'Error', description: 'No se pudo crear la organización.' }
      toast.error(message.title, message.description)
    } finally {
      putSaving(false)
    }
  })

  return (
    <form id={formId} onSubmit={onSubmit}>
      <FieldGroup>
        <RequiredFieldsLegend />

        <FieldSet>
          <FieldLegend>Datos de la organización</FieldLegend>
          <FieldDescription>Información básica del espacio de trabajo</FieldDescription>
          <FieldGroup>
            <Field>
              <FieldLabel htmlFor='tenant-name' required>
                Nombre
              </FieldLabel>
              <Input
                id='tenant-name'
                placeholder='Ingresa el nombre de la organización'
                {...register('name')}
                disabled={isSaving}
              />
              <FieldError>{errors.name?.message}</FieldError>
            </Field>
            <Field>
              <FieldLabel htmlFor='tenant-timezone' required>
                Zona horaria
              </FieldLabel>
              <Controller
                name='timezone'
                control={control}
                render={({ field }) => (
                  <TimeZoneSelect
                    id='tenant-timezone'
                    value={field.value}
                    onChange={field.onChange}
                    disabled={isSaving}
                  />
                )}
              />
              <FieldDescription>
                Las fechas y horas se mostrarán en esta zona horaria.
              </FieldDescription>
              <FieldError>{errors.timezone?.message}</FieldError>
            </Field>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Modo de provisión</FieldLegend>
          <FieldDescription>Define cómo arranca la facturación de la organización</FieldDescription>
          <Controller
            name='provision_mode'
            control={control}
            render={({ field }) => (
              <RadioGroup
                value={field.value}
                onValueChange={field.onChange}
                className='grid grid-cols-1 gap-3 sm:grid-cols-2'
                disabled={isSaving}
              >
                {PROVISION_MODES.map((mode) => {
                  const config = PROVISION_MODE_CONFIG[mode]
                  const isActive = field.value === mode
                  const radioId = `provision-mode-${mode}`
                  return (
                    <label
                      key={mode}
                      htmlFor={radioId}
                      className={cn(
                        'flex cursor-pointer items-start gap-3 rounded-none border p-4 transition-colors',
                        isActive ? 'border-primary bg-primary/5' : 'hover:border-primary/40'
                      )}
                    >
                      <RadioGroupItem id={radioId} value={mode} className='mt-0.5' />
                      <div className='min-w-0'>
                        <p className='text-sm font-semibold text-foreground'>{config.label}</p>
                        <p className='mt-1 text-xs leading-snug text-muted-foreground'>
                          {config.description}
                        </p>
                      </div>
                    </label>
                  )
                })}
              </RadioGroup>
            )}
          />
          <FieldError>{errors.provision_mode?.message}</FieldError>
        </FieldSet>

        {/* El método de pago solo aplica a los modos que cobran (tarjeta o pago
            manual). En cortesía y prueba no se cobra nada al provisionar, así que
            mostrarlo sería contradictorio; el valor RHF sigue siendo válido por el
            useEffect de allowedMethods y el dueño puede definirlo luego al convertir. */}
        {(provisionMode === 'card' || provisionMode === 'manual_paid') && (
          <FieldSet>
            <FieldLegend>Método de pago</FieldLegend>
            <FieldDescription>Cómo pagará la organización su suscripción</FieldDescription>
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor='tenant-payment-method' required>
                  Método de pago
                </FieldLabel>
                <Controller
                  name='payment_method'
                  control={control}
                  render={({ field }) => (
                    <Select
                      value={field.value || undefined}
                      onValueChange={field.onChange}
                      disabled={isSaving || methods.length === 1}
                    >
                      <SelectTrigger id='tenant-payment-method' className='w-full'>
                        <SelectValue placeholder='Seleccionar método de pago...' />
                      </SelectTrigger>
                      <SelectContent>
                        {methods.map((method) => (
                          <SelectItem key={method} value={method}>
                            {PAYMENT_METHOD_LABELS[method]}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{errors.payment_method?.message}</FieldError>
              </Field>
            </FieldGroup>
          </FieldSet>
        )}

        {needsPlan && (
          <FieldSet>
            <FieldLegend>Plan de la organización</FieldLegend>
            <FieldDescription>
              {provisionMode === 'manual_paid'
                ? 'Elige unidades y plan; el monto a registrar lo calcula el sistema.'
                : 'Elige el plan que se otorga como cortesía.'}
            </FieldDescription>
            <WizardPlanSelector
              showPrice={provisionMode === 'manual_paid'}
              onChange={handlePlanChange}
            />
            {isContactSalesOnPaidMode && (
              <p
                role='alert'
                className='border border-border bg-secondary p-3 text-sm text-foreground'
              >
                El plan a medida no tiene precio de lista, así que no se puede registrar un pago. Da
                de alta la organización como cortesía y registra el cobro cuando el equipo comercial
                cierre el precio.
              </p>
            )}
          </FieldSet>
        )}

        {provisionMode === 'manual_paid' && (
          <FieldSet>
            <FieldLegend>Referencia del pago</FieldLegend>
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor='tenant-notes'>Notas</FieldLabel>
                <Textarea
                  id='tenant-notes'
                  placeholder='Ej. Transferencia SPEI folio 0001'
                  className='resize-none'
                  rows={3}
                  {...register('notes')}
                  disabled={isSaving}
                />
                <FieldDescription>
                  Opcional. Queda asentado en el historial de pagos.
                </FieldDescription>
              </Field>
            </FieldGroup>
          </FieldSet>
        )}
      </FieldGroup>
    </form>
  )
}
