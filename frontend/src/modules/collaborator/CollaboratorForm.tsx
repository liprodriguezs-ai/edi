import { Button } from '@/components/ui/button'
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
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  collaboratorFormSchema,
  normalizeWorkDays,
  WORK_DAY_OPTIONS,
  type CollaboratorFormValues,
  type ICollaborator,
  type WorkDay,
} from '@/modules/collaborator/schema'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import { useCollaboratorStore } from '@/modules/collaborator/store/collaboratorStore'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { minorUnitsToPesos, moneyToMinorUnits, pesosToMinorUnits } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useRef } from 'react'
import { Controller, useForm, type Control } from 'react-hook-form'

interface CollaboratorFormProps extends IFormProps<ICollaborator> {
  onSubmitRef?: React.MutableRefObject<(() => void) | null>
}

// ─── Pure helpers ────────────────────────────────────────────────────────────

function str(value: string | undefined | null, fallback = ''): string {
  return value ?? fallback
}

function optStr(value: string | undefined | null): string | undefined {
  return value ?? undefined
}

function dateSlice(value: string | undefined | null, fallback = ''): string {
  return value != null ? value.slice(0, 10) : fallback
}

function buildBaseSalary(entity: ICollaborator | undefined): number | null {
  if (entity?.base_salary == null) return null
  return minorUnitsToPesos(moneyToMinorUnits(entity.base_salary as never))
}

function buildPersonalFields(entity: ICollaborator | undefined) {
  return {
    name: str(entity?.name),
    last_name: str(entity?.last_name),
    second_last_name: str(entity?.second_last_name),
    email: str(entity?.email),
    phone: str(entity?.phone),
    address: str(entity?.address),
    date_of_birth: dateSlice(entity?.date_of_birth),
  }
}

function buildPositionFields(entity: ICollaborator | undefined) {
  return {
    employee_position_id: str(entity?.employee_position_id),
    pay_frequency_id: optStr(entity?.pay_frequency_id),
    employee_shift_id: optStr(entity?.employee_shift_id),
    base_salary: buildBaseSalary(entity),
    work_days: normalizeWorkDays(entity?.work_days),
    hire_date: dateSlice(entity?.hire_date, new Date().toISOString().slice(0, 10)),
    specific_schedule: str(entity?.specific_schedule),
  }
}

function buildIdentificationFields(entity: ICollaborator | undefined) {
  return {
    rfc: str(entity?.rfc),
    curp: str(entity?.curp),
    nss: str(entity?.nss),
    create_as_user: Boolean(entity?.create_as_user ?? false),
  }
}

function buildDefaultValues(entity: ICollaborator | undefined): CollaboratorFormValues {
  return {
    ...buildPersonalFields(entity),
    ...buildPositionFields(entity),
    ...buildIdentificationFields(entity),
  }
}

function buildSubmitPayload(values: CollaboratorFormValues): Record<string, unknown> {
  return {
    ...values,
    email: values.email || null,
    base_salary: values.base_salary != null ? pesosToMinorUnits(values.base_salary) : null,
    pay_frequency_id: values.pay_frequency_id || null,
    employee_shift_id: values.employee_shift_id || null,
    date_of_birth: values.date_of_birth || null,
    // El backend acepta `nullable|array`: sin días elegidos mandamos null en vez
    // de un array vacío, para no persistir `[]` como si fuera un dato.
    work_days: values.work_days.length > 0 ? values.work_days : null,
  }
}

// ─── CatalogSelect subcomponent ───────────────────────────────────────────────

interface CatalogItem {
  id: string
  name: string
}

interface CatalogSelectProps {
  name: 'employee_position_id' | 'pay_frequency_id' | 'employee_shift_id'
  label: string
  required?: boolean
  control: Control<CollaboratorFormValues>
  error: string | undefined
  disabled: boolean
  items: CatalogItem[]
}

function CatalogSelect({
  name,
  label,
  required = false,
  control,
  error,
  disabled,
  items,
}: CatalogSelectProps) {
  return (
    <Field className='col-span-12 md:col-span-4'>
      <FieldLabel htmlFor={`employee-${name}`} required={required}>
        {label}
      </FieldLabel>
      <Controller
        name={name}
        control={control}
        render={({ field }) => (
          <SearchSelect
            id={`employee-${name}`}
            value={field.value}
            onChange={(v) => field.onChange((v as string | null) ?? '')}
            options={items}
            isDisabled={disabled}
            invalid={!!error}
            placeholder='Seleccionar…'
          />
        )}
      />
      <FieldError>{error}</FieldError>
    </Field>
  )
}

// ─── Custom hook ──────────────────────────────────────────────────────────────

const CATALOGS_CONFIG = {
  [CatalogType.EMPLOYEE_POSITION]: { _sort: 'order' },
  [CatalogType.PAY_FREQUENCY]: { _sort: 'order' },
  [CatalogType.EMPLOYEE_SHIFT]: { _sort: 'order' },
}

function useCollaboratorForm(
  entity: Partial<ICollaborator> | null | undefined,
  mode: CrudMode,
  onSuccess: ((result: ICollaborator) => void) | undefined,
  onSubmitRef: React.MutableRefObject<(() => void) | null> | undefined
) {
  const defaultValues = useMemo<CollaboratorFormValues>(
    () => buildDefaultValues(entity as ICollaborator | undefined),
    [entity]
  )

  const crud = useBaseCrud<ICollaborator, Record<string, unknown>>({
    store: useCollaboratorStore,
    resource: {
      save: (payload) => CollaboratorResource.create(payload as never),
      update: (id, payload) => CollaboratorResource.update(id, payload as never),
      delete: CollaboratorResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: (entity ?? undefined) as ICollaborator | undefined,
  })

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<CollaboratorFormValues>({
    resolver: zodResolver(collaboratorFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const { catalogs } = useCatalogs(useMemo(() => CATALOGS_CONFIG, []))

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(buildSubmitPayload(values))
    if (result) onSuccess?.(result)
  })

  const doSubmitRef = useRef(doSubmit)
  doSubmitRef.current = doSubmit
  useEffect(() => {
    if (onSubmitRef) {
      onSubmitRef.current = () => void doSubmitRef.current()
    }
  }, [onSubmitRef])

  return { register, control, errors, catalogs, isSaving: crud.store.isSaving, doSubmit }
}

// ─── Component ────────────────────────────────────────────────────────────────

function handleFormSubmit(
  e: React.FormEvent<HTMLFormElement>,
  doSubmit: (e: React.BaseSyntheticEvent) => Promise<void>
) {
  e.preventDefault()
  void doSubmit(e)
}

/**
 * Alta/edición de personal operativo (§7.4.2): datos de contacto + puesto,
 * salario (minor units), frecuencia de pago, turno y fechas.
 */
function parseSalaryValue(v: unknown): number | null {
  if (v === '' || v == null) return null
  return Number(v)
}

function resolveButtonLabel(isSaving: boolean, mode: CrudMode): string {
  if (isSaving) return 'Guardando…'
  if (mode === CrudMode.EDIT) return 'Actualizar'
  return 'Guardar'
}

export const CollaboratorForm = ({
  mode,
  entity,
  onSuccess,
  onSubmitRef,
  formId,
}: CollaboratorFormProps) => {
  const { register, control, errors, catalogs, isSaving, doSubmit } = useCollaboratorForm(
    entity,
    mode,
    onSuccess,
    onSubmitRef
  )

  const positionItems = (catalogs[CatalogType.EMPLOYEE_POSITION] ?? []) as CatalogItem[]
  const frequencyItems = (catalogs[CatalogType.PAY_FREQUENCY] ?? []) as CatalogItem[]
  const shiftItems = (catalogs[CatalogType.EMPLOYEE_SHIFT] ?? []) as CatalogItem[]

  const errName = errors.name?.message
  const errLastName = errors.last_name?.message
  const errEmail = errors.email?.message
  const errPosition = errors.employee_position_id?.message
  const errFrequency = errors.pay_frequency_id?.message
  const errShift = errors.employee_shift_id?.message
  const errSalary = errors.base_salary?.message
  const errHireDate = errors.hire_date?.message
  const errWorkDays = errors.work_days?.message
  const errRfc = errors.rfc?.message
  const errCurp = errors.curp?.message

  const buttonLabel = resolveButtonLabel(isSaving, mode)
  const showSubmitButton = !formId && !onSubmitRef

  return (
    <form id={formId} onSubmit={(e) => handleFormSubmit(e, doSubmit)}>
      <FieldGroup>
        <RequiredFieldsLegend />
        <FieldSet>
          <FieldLegend>Datos personales</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='employee-name' required>
                  Nombre
                </FieldLabel>
                <Input id='employee-name' {...register('name')} disabled={isSaving} />
                <FieldError>{errName}</FieldError>
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-last' required>
                  Apellido paterno
                </FieldLabel>
                <Input id='employee-last' {...register('last_name')} disabled={isSaving} />
                <FieldError>{errLastName}</FieldError>
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-second'>Apellido materno</FieldLabel>
                <Input id='employee-second' {...register('second_last_name')} disabled={isSaving} />
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-phone'>Teléfono</FieldLabel>
                <Input
                  id='employee-phone'
                  type='tel'
                  placeholder='777 123 4567'
                  {...register('phone')}
                  disabled={isSaving}
                />
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-email'>Correo</FieldLabel>
                <Input
                  id='employee-email'
                  type='email'
                  placeholder='correo@ejemplo.mx'
                  {...register('email')}
                  disabled={isSaving}
                />
                <FieldError>{errEmail}</FieldError>
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-birth'>Fecha de nacimiento</FieldLabel>
                <Input
                  id='employee-birth'
                  type='date'
                  {...register('date_of_birth')}
                  disabled={isSaving}
                />
              </Field>
              <Field className='col-span-12'>
                <FieldLabel htmlFor='employee-address'>Dirección</FieldLabel>
                <Textarea
                  id='employee-address'
                  rows={2}
                  className='resize-none'
                  {...register('address')}
                  disabled={isSaving}
                />
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Puesto y condiciones</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <CatalogSelect
                name='employee_position_id'
                label='Puesto'
                required
                control={control}
                error={errPosition}
                disabled={isSaving}
                items={positionItems}
              />
              <CatalogSelect
                name='pay_frequency_id'
                label='Frecuencia de pago'
                control={control}
                error={errFrequency}
                disabled={isSaving}
                items={frequencyItems}
              />
              <CatalogSelect
                name='employee_shift_id'
                label='Turno'
                control={control}
                error={errShift}
                disabled={isSaving}
                items={shiftItems}
              />

              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-salary'>Salario base</FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Input
                    id='employee-salary'
                    type='number'
                    step='0.01'
                    min={0}
                    className='pl-7'
                    placeholder='0.00'
                    {...register('base_salary', { setValueAs: parseSalaryValue })}
                    disabled={isSaving}
                  />
                </div>
                <FieldError>{errSalary}</FieldError>
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='employee-hire' required>
                  Fecha de alta
                </FieldLabel>
                <Input
                  id='employee-hire'
                  type='date'
                  {...register('hire_date')}
                  disabled={isSaving}
                />
                <FieldError>{errHireDate}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='employee-days'>Días laborales</FieldLabel>
                <Controller
                  name='work_days'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='employee-days'
                      value={field.value}
                      onChange={(v) => field.onChange((v as WorkDay[] | null) ?? [])}
                      options={WORK_DAY_OPTIONS}
                      multiple
                      collapseSelectedToCount
                      isDisabled={isSaving}
                      invalid={!!errWorkDays}
                      placeholder='Seleccionar días…'
                    />
                  )}
                />
                <FieldError>{errWorkDays}</FieldError>
              </Field>
              <Field className='col-span-12'>
                <FieldLabel htmlFor='employee-schedule'>Horario específico</FieldLabel>
                <Input
                  id='employee-schedule'
                  placeholder='7:00–15:00'
                  {...register('specific_schedule')}
                  disabled={isSaving}
                />
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Identificación</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='employee-rfc'>RFC</FieldLabel>
                <Input id='employee-rfc' {...register('rfc')} disabled={isSaving} />
                <FieldError>{errRfc}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='employee-curp'>CURP</FieldLabel>
                <Input id='employee-curp' {...register('curp')} disabled={isSaving} />
                <FieldError>{errCurp}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='employee-nss'>NSS</FieldLabel>
                <Input id='employee-nss' {...register('nss')} disabled={isSaving} />
              </Field>
              <Field className='col-span-12'>
                <FieldLabel htmlFor='employee-user'>Acceso al sistema</FieldLabel>
                <div className='flex items-center gap-3'>
                  <Controller
                    name='create_as_user'
                    control={control}
                    render={({ field }) => (
                      <Switch
                        id='employee-user'
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        disabled={isSaving}
                      />
                    )}
                  />
                  <FieldDescription className='m-0'>
                    Crear usuario con rol Personal Operativo (requiere correo)
                  </FieldDescription>
                </div>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        {showSubmitButton && (
          <Field orientation='horizontal' className='justify-end border-t pt-6'>
            <Button type='submit' disabled={isSaving} className='min-w-[120px]'>
              {buttonLabel}
            </Button>
          </Field>
        )}
      </FieldGroup>
    </form>
  )
}
