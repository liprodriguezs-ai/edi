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
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { buildAmenityPayload } from '@/modules/amenity/buildAmenityPayload'
import { AmenityBlackoutsEditor } from '@/modules/amenity/components/AmenityBlackoutsEditor'
import { AmenityCostDepositFields } from '@/modules/amenity/components/AmenityCostDepositFields'
import { AmenityGallery } from '@/modules/amenity/components/AmenityGallery'
import { AmenitySlotsEditor } from '@/modules/amenity/components/AmenitySlotsEditor'
import { AmenityTargetFields } from '@/modules/amenity/components/AmenityTargetFields'
import {
  RESERVATION_MODE,
  WEEKDAY_LABELS,
  amenityFormSchema,
  buildAmenityFormDefaults,
  type AmenityFormValues,
  type IAmenity,
} from '@/modules/amenity/schema'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'

export interface AmenityFormProps {
  mode: CrudMode
  amenity?: IAmenity | null
  onSaved: (amenity: IAmenity, wasCreate: boolean) => void
  onCancel: () => void
}

/**
 * Formulario de alta/edición de una amenidad, pensado para vivir en una PAGE
 * (sin chrome de Sheet) — igual que Announcement, dado el volumen de campos +
 * editores anidados (horarios/bloqueos/galería). Estos últimos solo aplican en
 * edición: requieren un `amenity_id` ya persistido (mismo motivo que la galería
 * de Announcement solo gestiona adjuntos "en línea" tras crear).
 */
export function AmenityForm({ mode, amenity, onSaved, onCancel }: AmenityFormProps) {
  const isEdit = mode === CrudMode.EDIT
  const queryClient = useQueryClient()
  const defaultValues = useMemo(() => buildAmenityFormDefaults(amenity), [amenity])

  const {
    register,
    handleSubmit,
    reset,
    control,
    setValue,
    formState: { errors },
  } = useForm<AmenityFormValues>({
    resolver: zodResolver(amenityFormSchema),
    defaultValues,
  })

  // Siembra el form al montar (alta) o al cargar la amenidad (edición). Guardamos por id para
  // NO re-sembrar cuando la amenidad se refetchea al gestionar horarios/bloqueos/fotos — eso
  // descartaría cambios sin guardar de los campos principales.
  const seededIdRef = useRef<string | null>(null)
  useEffect(() => {
    const nextId = amenity?.id ?? null
    if (seededIdRef.current === nextId) return
    seededIdRef.current = nextId
    reset(defaultValues)
  }, [amenity, defaultValues, reset])

  const [
    hasCost,
    requiresDeposit,
    outOfService,
    reservationModeId,
    targetType,
    targetUnitId,
    targetUnitIds,
  ] = useWatch({
    control,
    name: [
      'has_cost',
      'requires_deposit',
      'out_of_service',
      'reservation_mode_id',
      'target_type',
      'target_unit_id',
      'target_unit_ids',
    ],
  })

  // Label visible de la unidad elegida en la rama legacy `single_unit` del alcance.
  const [targetUnitLabel, setTargetUnitLabel] = useState('')

  // Esta es una PAGE dedicada al form: navegar hasta el editor ES "abrir el formulario", así
  // que cargar los catálogos al montar es el patrón load-on-form-open (§2.12), no carga eager
  // de una lista.
  const { catalogs, isLoading: catalogsLoading } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.RESERVATION_MODE]: { _sort: 'order' },
        [CatalogType.BANK_ACCOUNT]: { _sort: 'name' },
        [CatalogType.UNIT_TYPE]: { _sort: 'name' },
        [CatalogType.TOWER]: { _sort: 'name' },
        [CatalogType.SECTION]: { _sort: 'name' },
        [CatalogType.UNIT_FEATURE]: { _sort: 'name' },
      }),
      []
    )
  )

  // Catálogos que alimenta el selector de alcance (`AmenityTargetFields`).
  const targetCatalogs = useMemo(
    () => ({
      unitTypes: catalogs[CatalogType.UNIT_TYPE] ?? [],
      towers: catalogs[CatalogType.TOWER] ?? [],
      sections: catalogs[CatalogType.SECTION] ?? [],
      features: catalogs[CatalogType.UNIT_FEATURE] ?? [],
    }),
    [catalogs]
  )

  const mutation = useMutation({
    mutationFn: async (values: AmenityFormValues) => {
      const payload = buildAmenityPayload(values)
      if (isEdit && amenity) {
        const updated = await AmenityResource.update(amenity.id, payload)
        return { saved: updated, wasCreate: false }
      }
      const created = await AmenityResource.create(payload)
      return { saved: created, wasCreate: true }
    },
    onSuccess: async ({ saved, wasCreate }) => {
      toast.success(wasCreate ? 'Amenidad creada' : 'Amenidad actualizada')
      if (!wasCreate) {
        await queryClient.invalidateQueries({ queryKey: ['amenity', 'detail', saved.id] })
      }
      onSaved(saved, wasCreate)
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo guardar la amenidad'
      ),
  })

  const toggleWeekday = (current: number[], day: number, onChange: (_next: number[]) => void) => {
    if (current.includes(day)) {
      onChange(current.filter((d) => d !== day))
    } else {
      onChange([...current, day].sort((a, b) => a - b))
    }
  }

  return (
    <form
      onSubmit={handleSubmit((values) => mutation.mutate(values))}
      className='rounded-[16px] border border-border bg-card p-6 shadow-sm md:p-8'
    >
      <FieldGroup>
        <RequiredFieldsLegend />

        <FieldSet>
          <FieldLegend>Información general</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='amenity-name' required>
                  Nombre
                </FieldLabel>
                <Input
                  id='amenity-name'
                  placeholder='Salón de eventos'
                  {...register('name')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>

              <Field className='col-span-12 md:col-span-3'>
                <FieldLabel htmlFor='amenity-capacity' required>
                  Capacidad
                </FieldLabel>
                <Input
                  id='amenity-capacity'
                  type='number'
                  min={1}
                  {...register('capacity', { valueAsNumber: true })}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.capacity?.message}</FieldError>
              </Field>

              <Field className='col-span-12 md:col-span-3'>
                <FieldLabel htmlFor='amenity-reservation-mode' required>
                  Modo de reservación
                </FieldLabel>
                <Controller
                  name='reservation_mode_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='amenity-reservation-mode'
                      // BUG FIX (E2E QA round 2): while catalogs are still loading,
                      // `options` is empty so SearchSelect can't resolve `field.value`
                      // (the amenity's already-saved reservation_mode_id) to a matching
                      // option — it fell back to showing the raw UUID as the label.
                      // Withholding `value` until catalogs load shows the loading
                      // placeholder instead; once loaded, the real value resolves
                      // against the now-populated options to the correct name.
                      value={catalogsLoading ? null : field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.RESERVATION_MODE] ?? []}
                      isDisabled={mutation.isPending || catalogsLoading}
                      isLoading={catalogsLoading}
                      invalid={!!errors.reservation_mode_id}
                      placeholder={catalogsLoading ? 'Cargando…' : 'Seleccionar…'}
                    />
                  )}
                />
                <FieldError>{errors.reservation_mode_id?.message}</FieldError>
              </Field>

              <Field className='col-span-12'>
                <FieldLabel htmlFor='amenity-description'>Descripción</FieldLabel>
                <Textarea
                  id='amenity-description'
                  rows={3}
                  className='resize-none'
                  placeholder='Descripción visible en el catálogo de residentes'
                  {...register('description')}
                  disabled={mutation.isPending}
                />
              </Field>

              <Field className='col-span-12'>
                <FieldLabel htmlFor='amenity-restrictions'>Restricciones</FieldLabel>
                <Textarea
                  id='amenity-restrictions'
                  rows={2}
                  className='resize-none'
                  placeholder='Ej. máximo de invitados, no se permite alcohol'
                  {...register('restrictions')}
                  disabled={mutation.isPending}
                />
              </Field>

              <Field className='col-span-12'>
                <FieldLabel required>Días disponibles</FieldLabel>
                <Controller
                  name='available_weekdays'
                  control={control}
                  render={({ field }) => (
                    <div className='flex flex-wrap gap-4'>
                      {WEEKDAY_LABELS.map((label, day) => (
                        <label
                          key={label}
                          className='flex cursor-pointer items-center gap-2 text-[13px]'
                        >
                          <Checkbox
                            checked={field.value.includes(day)}
                            onCheckedChange={() => toggleWeekday(field.value, day, field.onChange)}
                            disabled={mutation.isPending}
                          />
                          {label}
                        </label>
                      ))}
                    </div>
                  )}
                />
                <FieldError>{errors.available_weekdays?.message}</FieldError>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <AmenityTargetFields
          control={control}
          register={register}
          errors={errors}
          targetType={targetType}
          targetUnitId={targetUnitId}
          targetUnitIds={targetUnitIds ?? []}
          catalogs={targetCatalogs}
          disabled={mutation.isPending}
          singleUnitLabel={targetUnitLabel}
          onSingleUnitLabelChange={setTargetUnitLabel}
          onUnitSelect={(id) => setValue('target_unit_id', id, { shouldValidate: true })}
          onUnitIdsChange={(ids) => setValue('target_unit_ids', ids, { shouldValidate: true })}
        />

        <AmenityCostDepositFields
          control={control}
          errors={errors}
          hasCost={hasCost}
          requiresDeposit={requiresDeposit}
          bankAccountOptions={catalogs[CatalogType.BANK_ACCOUNT] ?? []}
          disabled={mutation.isPending}
          catalogsLoading={catalogsLoading}
        />

        <FieldSet>
          <FieldLegend>Reglas y disponibilidad</FieldLegend>
          <FieldGroup>
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='amenity-requires-approval'>Requiere aprobación</FieldLabel>
                <Controller
                  name='requires_approval'
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id='amenity-requires-approval'
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={mutation.isPending}
                    />
                  )}
                />
                <FieldDescription>Un admin/directivo debe aprobar la reserva</FieldDescription>
              </Field>

              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='amenity-out-of-service'>Fuera de servicio</FieldLabel>
                <Controller
                  name='out_of_service'
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id='amenity-out-of-service'
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={mutation.isPending}
                    />
                  )}
                />
                <FieldDescription>Bloquea toda reservación mientras esté activo</FieldDescription>
              </Field>

              {outOfService && (
                <Field className='col-span-12 md:col-span-4'>
                  <FieldLabel htmlFor='amenity-out-of-service-reason'>Motivo</FieldLabel>
                  <Input
                    id='amenity-out-of-service-reason'
                    placeholder='Ej. mantenimiento de la alberca'
                    {...register('out_of_service_reason')}
                    disabled={mutation.isPending}
                  />
                </Field>
              )}

              <Field className='col-span-12'>
                <FieldLabel htmlFor='amenity-rules'>Reglas de uso</FieldLabel>
                <Textarea
                  id='amenity-rules'
                  rows={4}
                  className='resize-none'
                  placeholder='Reglas que el residente debe aceptar antes de reservar'
                  {...register('rules_text')}
                  disabled={mutation.isPending}
                />
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        {/* Horarios/bloqueos/galería son detalles de una amenidad YA persistida — en alta se
            explica que se configuran después de guardar (mismo motivo que Announcement solo
            gestiona adjuntos "en línea" en edición). */}
        {isEdit && amenity ? (
          <>
            {reservationModeId === RESERVATION_MODE.BY_SLOT && (
              <AmenitySlotsEditor
                amenityId={amenity.id}
                slots={amenity.slots ?? []}
                disabled={mutation.isPending}
              />
            )}
            <AmenityBlackoutsEditor
              amenityId={amenity.id}
              blackouts={amenity.blackouts ?? []}
              disabled={mutation.isPending}
            />
            <AmenityGallery
              amenityId={amenity.id}
              media={amenity.media ?? []}
              disabled={mutation.isPending}
            />
          </>
        ) : (
          <FieldDescription>
            Guarda la amenidad para configurar horarios, bloqueos de fechas y la galería de fotos.
          </FieldDescription>
        )}

        <Field orientation='horizontal' className='justify-end border-t border-border pt-6'>
          <Button
            type='button'
            variant='outline'
            onClick={onCancel}
            disabled={mutation.isPending}
            className='min-w-[80px]'
          >
            Cancelar
          </Button>
          <Button type='submit' disabled={mutation.isPending} className='min-w-[140px]'>
            {mutation.isPending ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear amenidad'}
          </Button>
        </Field>
      </FieldGroup>
    </form>
  )
}
