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
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { companyFormSchema, type CompanyFormValues, type ICompany } from '@/modules/company/schema'
import { CompanyResource } from '@/modules/company/services/CompanyResource'
import { useCompanyStore } from '@/modules/company/store/companyStore'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { toast } from '@/shared/hooks/useToast'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { type Resolver, Controller, useForm } from 'react-hook-form'
import { FormFooter } from '@/shared/components/molecules/FormFooter'

export interface CompanyFormProps extends IFormProps<ICompany> {
  readonly?: boolean
}

export const CompanyForm = ({
  mode,
  entity,
  onSuccess,
  onCancel,
  formId,
  readonly = false,
}: CompanyFormProps) => {
  const defaultValues = useMemo<CompanyFormValues>(() => {
    const baseDefaults: CompanyFormValues = {
      name: '',
      description: '',
      rfc: '',
      legal_name: '',
      legal_representative: '',
      employer_registration: '',
      fiscal_address: '',
      industry: '',
      employee_count: 50,
      constitutive_deed_number: '',
      constitutive_deed_date: '',
      constitutive_notary_number: '',
      constitutive_notary_name: '',
      constitutive_notary_state: '',
    }

    if (!entity) return baseDefaults

    const merged = { ...baseDefaults, ...entity }
    // API can return null for nullable columns; schema requires non-empty string / number
    if (merged.employee_count == null || typeof merged.employee_count !== 'number') {
      merged.employee_count = baseDefaults.employee_count
    }
    return merged
  }, [entity])

  const crud = useBaseCrud<ICompany, CompanyFormValues>({
    store: useCompanyStore,
    resource: {
      save: CompanyResource.create,
      update: CompanyResource.update,
      delete: CompanyResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors },
  } = useForm<CompanyFormValues>({
    resolver: zodResolver(companyFormSchema) as Resolver<CompanyFormValues>,
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const onValid = async (values: CompanyFormValues) => {
    try {
      const result = await crud.submit(values)
      if (result) {
        onSuccess?.(result)
      }
    } catch (err) {
      toast.error('No se pudo guardar. Revisa los datos e intenta de nuevo.')
      throw err
    }
  }

  const onInvalid = (errs: Partial<Record<keyof CompanyFormValues, { message?: string }>>) => {
    const first = Object.values(errs)[0]?.message
    toast.error(first ?? 'Revisa los campos del formulario.')
  }

  const onFormSubmit = handleSubmit(onValid, onInvalid)

  const isSaving = crud.store.isSaving
  const isDisabled = isSaving || readonly

  return (
    <form
      id={formId}
      onSubmit={onFormSubmit}
      aria-readonly={readonly}
      noValidate
    >
      <FieldGroup>
        <RequiredFieldsLegend />
        <div className="grid grid-cols-1 gap-x-8 gap-y-6 md:grid-cols-2">
          {/* Columna izquierda */}
          <div className="space-y-6">
            <FieldSet>
              <FieldLegend>Información General</FieldLegend>
              <FieldDescription>Datos principales de la empresa</FieldDescription>
              <FieldGroup>
                <Field>
                  <FieldLabel htmlFor="company-name" required>
                    Nombre de la empresa
                  </FieldLabel>
                  <Input
                    id="company-name"
                    placeholder="Ingresa el nombre de la empresa"
                    {...register('name')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                  <FieldError>{errors.name?.message}</FieldError>
                </Field>

                <Field>
                  <FieldLabel htmlFor="company-legal-name">Razón social</FieldLabel>
                  <Input
                    id="company-legal-name"
                    placeholder="Empresa S.A. de C.V."
                    {...register('legal_name')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="company-rfc">RFC</FieldLabel>
                  <Input
                    id="company-rfc"
                    className="font-mono"
                    placeholder="XAXX010101000"
                    {...register('rfc')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="company-description">Descripción</FieldLabel>
                  <Textarea
                    id="company-description"
                    placeholder="Breve descripción de la empresa..."
                    className="resize-none"
                    rows={3}
                    {...register('description')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                  <FieldDescription>Máximo 500 caracteres</FieldDescription>
                </Field>
              </FieldGroup>
            </FieldSet>

            <FieldSet>
              <FieldLegend>Datos Fiscales y Legales</FieldLegend>
              <FieldDescription>Información adicional para contratos y plantillas</FieldDescription>
              <FieldGroup>
                <Field>
                  <FieldLabel htmlFor="company-legal-representative">Representante legal</FieldLabel>
                  <Input
                    id="company-legal-representative"
                    placeholder="Nombre del representante legal"
                    {...register('legal_representative')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="company-employer-registration">Registro patronal</FieldLabel>
                  <Input
                    id="company-employer-registration"
                    placeholder="Registro patronal"
                    {...register('employer_registration')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="company-fiscal-address">Domicilio fiscal</FieldLabel>
                  <Textarea
                    id="company-fiscal-address"
                    rows={3}
                    placeholder="Domicilio fiscal completo"
                    className="resize-none"
                    {...register('fiscal_address')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                </Field>
              </FieldGroup>
            </FieldSet>
          </div>

          {/* Columna derecha */}
          <div className="space-y-6">
            <FieldSet>
              <FieldLegend>Acta Constitutiva</FieldLegend>
              <FieldDescription>Datos notariales para la generación de contratos</FieldDescription>
              <FieldGroup>
                <div className="grid grid-cols-2 gap-4">
                  <Field>
                    <FieldLabel htmlFor="company-deed-number">Núm. de acta</FieldLabel>
                    <Input
                      id="company-deed-number"
                      placeholder="Ej. 12345"
                      {...register('constitutive_deed_number')}
                      disabled={isDisabled}
                      aria-readonly={readonly}
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="company-deed-date">Fecha de expedición</FieldLabel>
                    <Input
                      id="company-deed-date"
                      type="date"
                      {...register('constitutive_deed_date')}
                      disabled={isDisabled}
                      aria-readonly={readonly}
                    />
                  </Field>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <Field>
                    <FieldLabel htmlFor="company-notary-number">Núm. de notario</FieldLabel>
                    <Input
                      id="company-notary-number"
                      placeholder="Número del notario"
                      {...register('constitutive_notary_number')}
                      disabled={isDisabled}
                      aria-readonly={readonly}
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="company-notary-state">Estado</FieldLabel>
                    <Input
                      id="company-notary-state"
                      placeholder="Ej. Jalisco"
                      {...register('constitutive_notary_state')}
                      disabled={isDisabled}
                      aria-readonly={readonly}
                    />
                  </Field>
                </div>

                <Field>
                  <FieldLabel htmlFor="company-notary-name">Nombre del notario</FieldLabel>
                  <Input
                    id="company-notary-name"
                    placeholder="Nombre completo"
                    {...register('constitutive_notary_name')}
                    disabled={isDisabled}
                    aria-readonly={readonly}
                  />
                </Field>
              </FieldGroup>
            </FieldSet>

            <FieldSet>
              <FieldLegend>Clasificación</FieldLegend>
              <FieldDescription>Industria y tamaño</FieldDescription>
              <FieldGroup>
                <Field>
                  <FieldLabel htmlFor="company-industry">Industria</FieldLabel>
                  <Controller
                    name="industry"
                    control={control}
                    render={({ field }) => (
                      <Select
                        value={field.value || undefined}
                        onValueChange={field.onChange}
                        disabled={isDisabled}
                      >
                        <SelectTrigger id="company-industry">
                          <SelectValue placeholder="Selecciona una industria" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectGroup>
                            <SelectItem value="technology">Tecnología</SelectItem>
                            <SelectItem value="finance">Finanzas</SelectItem>
                            <SelectItem value="healthcare">Salud</SelectItem>
                            <SelectItem value="education">Educación</SelectItem>
                            <SelectItem value="retail">Comercio</SelectItem>
                            <SelectItem value="manufacturing">Manufactura</SelectItem>
                            <SelectItem value="services">Servicios</SelectItem>
                            <SelectItem value="other">Otro</SelectItem>
                          </SelectGroup>
                        </SelectContent>
                      </Select>
                    )}
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="company-employee-count">Número de empleados</FieldLabel>
                  <Controller
                    name="employee_count"
                    control={control}
                    render={({ field }) => (
                      <Input
                        id="company-employee-count"
                        type="number"
                        min={1}
                        max={500}
                        step={1}
                        className="font-mono tabular-nums"
                        value={field.value ?? ''}
                        onChange={(e) =>
                          field.onChange(e.target.value === '' ? undefined : Number(e.target.value))
                        }
                        disabled={isDisabled}
                        aria-readonly={readonly}
                      />
                    )}
                  />
                </Field>
              </FieldGroup>
            </FieldSet>
          </div>
        </div>

        {!formId && !readonly && (
          <FormFooter>
            {onCancel && (
              <Button
                type="button"
                variant="outline"
                onClick={onCancel}
                disabled={isSaving}
                className="min-w-[80px]"
              >
                Cancelar
              </Button>
            )}
            <Button type="submit" disabled={isSaving} className="min-w-[100px]">
              {isSaving
                ? mode === CrudMode.EDIT
                  ? 'Actualizando...'
                  : 'Creando...'
                : mode === CrudMode.EDIT
                  ? 'Actualizar'
                  : 'Crear'}
            </Button>
          </FormFooter>
        )}

        {!formId && readonly && onCancel && (
          <FormFooter>
            <Button
              type="button"
              variant="outline"
              onClick={onCancel}
              className="min-w-[80px]"
            >
              Volver
            </Button>
          </FormFooter>
        )}
      </FieldGroup>
    </form>
  )
}
