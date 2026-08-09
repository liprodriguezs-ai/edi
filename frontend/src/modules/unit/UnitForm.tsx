import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
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
import { Textarea } from '@/components/ui/textarea'
import { buildUnitFormSchema, type IUnit, type UnitFormValues } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { useUnitStore } from '@/modules/unit/store/unitStore'
import { ApiError } from '@/shared/api/apiClient'
import { BUSINESS_CODES } from '@/shared/api/businessCodes'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

/** Características sugeridas (§6.3.3) — se pueden agregar personalizadas */
const SUGGESTED_FEATURES = [
  'Cochera doble',
  'Esquina',
  'Frente a calle principal',
  'Acceso para discapacitados',
]

interface UnitFormProps extends IFormProps<IUnit> {
  onSubmitRef?: React.MutableRefObject<(() => void) | null>
  onUnitLimitReached?: (_unitLimit: number) => void
}

// ─── helpers ────────────────────────────────────────────────────────────────

function toOptionalNumber(value: number | string | null | undefined): number | null {
  if (value == null || value === '') return null
  return Number(value)
}

function buildIdentificationDefaults(
  entity: IUnit | undefined
): Pick<
  UnitFormValues,
  | 'unit_type_id'
  | 'number'
  | 'street'
  | 'interior_number'
  | 'unit_status_id'
  | 'notes'
  | 'characteristics'
> {
  return {
    unit_type_id: entity ? entity.unit_type_id : '',
    number: entity ? (entity.number ?? '') : '',
    street: entity ? (entity.street ?? '') : '',
    interior_number: entity ? (entity.interior_number ?? '') : '',
    unit_status_id: entity ? entity.unit_status_id : '',
    notes: entity ? (entity.notes ?? '') : '',
    characteristics: entity ? (entity.characteristics ?? []) : [],
  }
}

function buildLocationDefaults(
  entity: IUnit | undefined
): Pick<UnitFormValues, 'tower_id' | 'floor' | 'section_id'> {
  return {
    tower_id: entity ? (entity.tower_id ?? undefined) : undefined,
    floor: entity ? (entity.floor ?? '') : '',
    section_id: entity ? (entity.section_id ?? undefined) : undefined,
  }
}

/**
 * `ownership_share` (indiviso) YA NO SE MUESTRA: se quitó de la UI porque el
 * prorrateo por indiviso no es alcanzable desde la aplicación. Se mantiene en los
 * defaults A PROPÓSITO para que el valor ya capturado se rehidrate y viaje de
 * vuelta sin cambios al editar la unidad. Si lo borras de acá, cada edición
 * mandaría el campo vacío y BORRARÍA el indiviso guardado de los clústeres que
 * ya lo tenían.
 */
function buildPhysicalDefaults(
  entity: IUnit | undefined
): Pick<UnitFormValues, 'built_m2' | 'land_m2' | 'ownership_share'> {
  return {
    built_m2: toOptionalNumber(entity ? entity.built_m2 : null),
    land_m2: toOptionalNumber(entity ? entity.land_m2 : null),
    ownership_share: toOptionalNumber(entity ? entity.ownership_share : null),
  }
}

function buildDefaultValues(entity: IUnit | null | undefined): UnitFormValues {
  const e = entity ?? undefined
  return {
    ...buildIdentificationDefaults(e),
    ...buildLocationDefaults(e),
    ...buildPhysicalDefaults(e),
  }
}

function getNumberFieldProps(isApartment: boolean) {
  return {
    colSpan: isApartment ? 'col-span-6 md:col-span-3' : 'col-span-12 md:col-span-6',
    label: isApartment ? 'Número de depto' : 'Número',
    placeholder: isApartment ? '502' : '23',
  }
}

function getSubmitLabel(isSaving: boolean, mode: IFormProps<IUnit>['mode']): string {
  if (isSaving) return 'Guardando…'
  if (mode === CrudMode.EDIT) return 'Actualizar'
  return 'Guardar unidad'
}

// ─── sub-components ──────────────────────────────────────────────────────────

interface CustomFeaturesProps {
  features: string[]
  isSaving: boolean
  onRemove: (feature: string) => void
}

function CustomFeaturePills({ features, isSaving, onRemove }: CustomFeaturesProps) {
  if (features.length === 0) return null
  return (
    <div className='flex flex-wrap gap-1.5'>
      {features.map((feature) => (
        <span
          key={feature}
          className='inline-flex items-center gap-1 rounded-full bg-[var(--green-bg)] px-2.5 py-1 text-xs font-medium text-[var(--green-deep)]'
        >
          {feature}
          <button
            type='button'
            onClick={() => onRemove(feature)}
            aria-label={`Quitar ${feature}`}
            className='hover:text-destructive'
            disabled={isSaving}
          >
            <X className='size-3' />
          </button>
        </span>
      ))}
    </div>
  )
}

// ─── hook ────────────────────────────────────────────────────────────────────

interface UseUnitFormReturn {
  form: ReturnType<typeof useForm<UnitFormValues>>
  isSaving: boolean
  catalogs: ReturnType<typeof useCatalogs>['catalogs']
  isApartment: boolean
  characteristics: string[]
  customFeature: string
  setCustomFeature: React.Dispatch<React.SetStateAction<string>>
  customFeatures: string[]
  toggleFeature: (feature: string, checked: boolean) => void
  addCustomFeature: () => void
  handleCustomKeyDown: (e: React.KeyboardEvent<HTMLInputElement>) => void
  doSubmit: (e?: React.BaseSyntheticEvent) => Promise<void>
}

function useUnitForm(
  entity: Partial<IUnit> | null | undefined,
  mode: IFormProps<IUnit>['mode'],
  onSuccess: IFormProps<IUnit>['onSuccess'],
  onSubmitRef: React.MutableRefObject<(() => void) | null> | undefined,
  onUnitLimitReached: ((_unitLimit: number) => void) | undefined
): UseUnitFormReturn {
  const defaultValues = useMemo<UnitFormValues>(
    () => buildDefaultValues(entity as IUnit | null | undefined),
    [entity]
  )

  const handleCrudError = useCallback(
    (err: ApiError): boolean => {
      if (err.payload?.code === BUSINESS_CODES.UNIT_LIMIT_EXCEEDED && onUnitLimitReached) {
        onUnitLimitReached(err.payload.unit_limit ?? 0)
        return true
      }
      return false
    },
    [onUnitLimitReached]
  )

  const crud = useBaseCrud<IUnit, UnitFormValues>({
    store: useUnitStore,
    resource: {
      save: UnitResource.create,
      update: UnitResource.update,
      delete: UnitResource.remove,
    },
    mode,
    entityId: entity ? entity.id : undefined,
    initialData: (entity ?? undefined) as IUnit | undefined,
    onError: handleCrudError,
  })

  const catalogRequest = useMemo(
    () => ({
      [CatalogType.UNIT_TYPE]: { _sort: 'order' },
      [CatalogType.UNIT_STATUS]: { _sort: 'order' },
      [CatalogType.TOWER]: { _sort: 'order' },
      [CatalogType.SECTION]: { _sort: 'order' },
    }),
    []
  )
  const { catalogs } = useCatalogs(catalogRequest)

  const requiresFloorIds = useMemo(() => {
    const rows = (catalogs[CatalogType.UNIT_TYPE] ?? []) as Array<{
      id: string
      requires_floor?: boolean
    }>
    return new Set(rows.filter((row) => row.requires_floor).map((row) => row.id))
  }, [catalogs])

  const schema = useMemo(() => buildUnitFormSchema(requiresFloorIds), [requiresFloorIds])

  const form = useForm<UnitFormValues>({
    resolver: zodResolver(schema),
    defaultValues,
  })

  useEffect(() => {
    form.reset(defaultValues)
  }, [form, defaultValues])

  const [customFeature, setCustomFeature] = useState('')

  const unitTypeId = form.watch('unit_type_id')
  const characteristics = form.watch('characteristics') ?? []
  const isApartment = requiresFloorIds.has(unitTypeId)

  const toggleFeature = useCallback(
    (feature: string, checked: boolean) => {
      const current = form.getValues('characteristics') ?? []
      const next = checked ? [...current, feature] : current.filter((f) => f !== feature)
      form.setValue('characteristics', next, { shouldDirty: true })
    },
    [form]
  )

  const addCustomFeature = useCallback(() => {
    const feature = customFeature.trim()
    const current = form.getValues('characteristics') ?? []
    if (feature && !current.includes(feature)) {
      form.setValue('characteristics', [...current, feature], { shouldDirty: true })
    }
    setCustomFeature('')
  }, [customFeature, form])

  const handleCustomKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        e.preventDefault()
        addCustomFeature()
      }
    },
    [addCustomFeature]
  )

  const doSubmit = form.handleSubmit(async (values) => {
    const payload: UnitFormValues = {
      ...values,
      tower_id: isApartment ? values.tower_id : undefined,
      floor: isApartment ? values.floor : undefined,
      section_id: values.section_id || undefined,
    }
    const result = await crud.submit(payload)
    if (result) onSuccess?.(result)
  })

  const doSubmitRef = useRef(doSubmit)
  useEffect(() => {
    doSubmitRef.current = doSubmit
  })

  useEffect(() => {
    if (onSubmitRef) {
      onSubmitRef.current = () => void doSubmitRef.current()
    }
  }, [onSubmitRef])

  const customFeatures = characteristics.filter((f) => !SUGGESTED_FEATURES.includes(f))

  return {
    form,
    isSaving: crud.store.isSaving,
    catalogs,
    isApartment,
    characteristics,
    customFeature,
    setCustomFeature,
    customFeatures,
    toggleFeature,
    addCustomFeature,
    handleCustomKeyDown,
    doSubmit,
  }
}

// ─── component ───────────────────────────────────────────────────────────────

export const UnitForm = ({
  mode,
  entity,
  onSuccess,
  onSubmitRef,
  onUnitLimitReached,
}: UnitFormProps) => {
  const {
    form,
    isSaving,
    catalogs,
    isApartment,
    characteristics,
    customFeature,
    setCustomFeature,
    customFeatures,
    toggleFeature,
    addCustomFeature,
    handleCustomKeyDown,
    doSubmit,
  } = useUnitForm(entity, mode, onSuccess, onSubmitRef, onUnitLimitReached)

  const {
    register,
    control,
    formState: { errors },
  } = form
  const numberField = getNumberFieldProps(isApartment)
  const submitLabel = getSubmitLabel(isSaving, mode)

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void doSubmit(e)
      }}
    >
      <FieldGroup>
        <RequiredFieldsLegend />
        <FieldSet>
          <FieldLegend>Identificación</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12'>
                <FieldLabel required>Tipo de unidad</FieldLabel>
                <Controller
                  name='unit_type_id'
                  control={control}
                  render={({ field }) => (
                    <RadioGroup
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={isSaving}
                      className='flex flex-wrap gap-4'
                    >
                      {catalogs[CatalogType.UNIT_TYPE]?.map((type) => (
                        <label
                          key={type.id}
                          className='flex cursor-pointer items-center gap-2 text-sm'
                        >
                          <RadioGroupItem value={type.id} />
                          {(type as { name?: string }).name}
                        </label>
                      ))}
                    </RadioGroup>
                  )}
                />
                <FieldError>{errors.unit_type_id?.message}</FieldError>
              </Field>

              {isApartment && (
                <>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='unit-tower' required>
                      Torre
                    </FieldLabel>
                    <Controller
                      name='tower_id'
                      control={control}
                      render={({ field }) => (
                        <SearchSelect
                          id='unit-tower'
                          value={field.value}
                          onChange={(v) => field.onChange((v as string | null) ?? '')}
                          options={catalogs[CatalogType.TOWER] ?? []}
                          isDisabled={isSaving}
                          invalid={!!errors.tower_id}
                          placeholder='Seleccionar torre…'
                        />
                      )}
                    />
                    <FieldError>{errors.tower_id?.message}</FieldError>
                  </Field>
                  <Field className='col-span-6 md:col-span-3'>
                    <FieldLabel htmlFor='unit-floor' required>
                      Piso
                    </FieldLabel>
                    <Input
                      id='unit-floor'
                      placeholder='5'
                      {...register('floor')}
                      disabled={isSaving}
                    />
                    <FieldError>{errors.floor?.message}</FieldError>
                  </Field>
                </>
              )}

              <Field className={numberField.colSpan}>
                <FieldLabel htmlFor='unit-number' required>
                  {numberField.label}
                </FieldLabel>
                <Input
                  id='unit-number'
                  placeholder={numberField.placeholder}
                  {...register('number')}
                  disabled={isSaving}
                />
                <FieldError>{errors.number?.message}</FieldError>
              </Field>

              <Field className='col-span-6 md:col-span-3'>
                <FieldLabel htmlFor='unit-interior-number'>Número interior</FieldLabel>
                <Input
                  id='unit-interior-number'
                  placeholder='4-B'
                  {...register('interior_number')}
                  disabled={isSaving}
                />
                <FieldError>{errors.interior_number?.message}</FieldError>
              </Field>

              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='unit-street'>Calle</FieldLabel>
                <Input
                  id='unit-street'
                  placeholder='Av. de los Cedros'
                  {...register('street')}
                  disabled={isSaving}
                />
                <FieldError>{errors.street?.message}</FieldError>
              </Field>

              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='unit-section'>Sección / Manzana</FieldLabel>
                <Controller
                  name='section_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='unit-section'
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.SECTION] ?? []}
                      isDisabled={isSaving}
                      placeholder='Sin sección'
                    />
                  )}
                />
              </Field>

              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='unit-status' required>
                  Estado
                </FieldLabel>
                <Controller
                  name='unit_status_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='unit-status'
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.UNIT_STATUS] ?? []}
                      isDisabled={isSaving}
                      invalid={!!errors.unit_status_id}
                      placeholder='Seleccionar estado…'
                    />
                  )}
                />
                <FieldError>{errors.unit_status_id?.message}</FieldError>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Datos físicos</FieldLegend>
          <FieldDescription>Opcionales</FieldDescription>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='unit-built'>M² construcción</FieldLabel>
                <Input
                  id='unit-built'
                  type='number'
                  step='0.01'
                  min={0}
                  placeholder='120'
                  {...register('built_m2', {
                    setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                  })}
                  disabled={isSaving}
                />
                <FieldError>{errors.built_m2?.message}</FieldError>
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='unit-land'>M² terreno</FieldLabel>
                <Input
                  id='unit-land'
                  type='number'
                  step='0.01'
                  min={0}
                  placeholder='180'
                  {...register('land_m2', {
                    setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                  })}
                  disabled={isSaving}
                />
                <FieldError>{errors.land_m2?.message}</FieldError>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Características</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-1 gap-2 sm:grid-cols-2'>
              {SUGGESTED_FEATURES.map((feature) => (
                <label key={feature} className='flex cursor-pointer items-center gap-2 text-sm'>
                  <Checkbox
                    checked={characteristics.includes(feature)}
                    onCheckedChange={(checked) => toggleFeature(feature, checked === true)}
                    disabled={isSaving}
                  />
                  {feature}
                </label>
              ))}
            </div>
            <CustomFeaturePills
              features={customFeatures}
              isSaving={isSaving}
              onRemove={(f) => toggleFeature(f, false)}
            />
            <div className='flex gap-2'>
              <Input
                value={customFeature}
                onChange={(e) => setCustomFeature(e.target.value)}
                onKeyDown={handleCustomKeyDown}
                placeholder='Característica personalizada…'
                disabled={isSaving}
              />
              <Button
                type='button'
                variant='outline'
                onClick={addCustomFeature}
                disabled={isSaving || !customFeature.trim()}
              >
                <Plus className='size-4' />
                Agregar
              </Button>
            </div>
          </FieldGroup>
        </FieldSet>

        <Field>
          <FieldLabel htmlFor='unit-notes'>Notas</FieldLabel>
          <Textarea
            id='unit-notes'
            rows={3}
            className='resize-none'
            placeholder='Notas administrativas…'
            {...register('notes')}
            disabled={isSaving}
          />
          <FieldError>{errors.notes?.message}</FieldError>
        </Field>

        {!onSubmitRef && (
          <Field orientation='horizontal' className='justify-end border-t pt-6'>
            <Button type='submit' disabled={isSaving} className='min-w-[120px]'>
              {submitLabel}
            </Button>
          </Field>
        )}
      </FieldGroup>
    </form>
  )
}
