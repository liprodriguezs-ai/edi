import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Switch } from '@/components/ui/switch'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import {
  caregiverFormSchema,
  type CaregiverFormValues,
  type ICaregiver,
} from '@/modules/caregiver/schema'
import { CaregiverResource } from '@/modules/caregiver/services/CaregiverResource'
import { useCaregiverStore } from '@/modules/caregiver/store/caregiverStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, type DefaultValues } from 'react-hook-form'

interface CaregiverFormModalProps {
  isOpen: boolean
  mode: CrudMode
  caregiver: ICaregiver | null
  onClose: () => void
  /** Se dispara tras crear/editar exitosamente con la entidad resultante. */
  onSaved: (_caregiver: ICaregiver) => void
}

/**
 * Alta/edición de un cuidador. Los catálogos (escolaridad, ocupación, tipo de
 * población) se piden solo mientras el Sheet está abierto (isOpen gatea el
 * montaje real, igual que CatalogFormModal).
 */
export function CaregiverFormModal(props: CaregiverFormModalProps) {
  if (!props.isOpen) return null
  return <CaregiverFormSheet {...props} />
}

function CaregiverFormSheet({ mode, caregiver, onClose, onSaved }: CaregiverFormModalProps) {
  const isSaving = useCaregiverStore((s) => s.isSaving)

  const crud = useBaseCrud<ICaregiver, CaregiverFormValues>({
    store: useCaregiverStore,
    resource: { save: CaregiverResource.create, update: CaregiverResource.update },
    mode,
    entityId: caregiver?.id,
    initialData: caregiver,
  })

  const defaultValues = useMemo<CaregiverFormValues>(
    () => ({
      first_name: caregiver?.first_name ?? '',
      last_name_paternal: caregiver?.last_name_paternal ?? '',
      last_name_maternal: caregiver?.last_name_maternal ?? '',
      birth_date: caregiver?.birth_date ?? '',
      education_level_id: caregiver?.education_level_id ?? '',
      occupation_id: caregiver?.occupation_id ?? '',
      population_type_id: caregiver?.population_type_id ?? '',
      phone: caregiver?.phone ?? '',
      email: caregiver?.email ?? '',
      has_social_program: caregiver?.has_social_program ?? false,
    }),
    [caregiver]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<CaregiverFormValues>({
    resolver: zodResolver(caregiverFormSchema),
    defaultValues: defaultValues as DefaultValues<CaregiverFormValues>,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs({
    [CatalogType.EDUCATION_LEVEL]: { _sort: 'name' },
    [CatalogType.OCCUPATION]: { _sort: 'name' },
    [CatalogType.POPULATION_TYPE]: { _sort: 'name' },
  })

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      onSaved(result)
      onClose()
    }
  })

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="right" className="flex flex-col gap-0 p-0 w-full sm:max-w-lg" aria-describedby={undefined}>
        <SheetHeader className="shrink-0 px-6 pt-6 pb-2">
          <SheetTitle>{mode === CrudMode.EDIT ? 'Editar cuidador' : 'Nuevo cuidador'}</SheetTitle>
        </SheetHeader>

        <form
          className="flex-1 overflow-y-auto px-6 py-2"
          onSubmit={(e) => {
            e.preventDefault()
            void doSubmit(e)
          }}
        >
          <RequiredFieldsLegend />
          <FieldGroup>
            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-first_name" required>
                  Nombre(s)
                </FieldLabel>
                <Input id="caregiver-first_name" disabled={isSaving} {...register('first_name')} />
                <FieldError>{errors.first_name?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-phone" required>
                  Teléfono
                </FieldLabel>
                <Input id="caregiver-phone" disabled={isSaving} {...register('phone')} />
                <FieldError>{errors.phone?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-last_name_paternal" required>
                  Apellido paterno
                </FieldLabel>
                <Input
                  id="caregiver-last_name_paternal"
                  disabled={isSaving}
                  {...register('last_name_paternal')}
                />
                <FieldError>{errors.last_name_paternal?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-last_name_maternal">Apellido materno</FieldLabel>
                <Input
                  id="caregiver-last_name_maternal"
                  placeholder="Opcional"
                  disabled={isSaving}
                  {...register('last_name_maternal')}
                />
                <FieldError>{errors.last_name_maternal?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-birth_date">Fecha de nacimiento</FieldLabel>
                <Input id="caregiver-birth_date" type="date" disabled={isSaving} {...register('birth_date')} />
                <FieldError>{errors.birth_date?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-email">Correo</FieldLabel>
                <Input id="caregiver-email" type="email" disabled={isSaving} {...register('email')} />
                <FieldError>{errors.email?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-4">
                <FieldLabel htmlFor="caregiver-education_level_id" required>
                  Escolaridad
                </FieldLabel>
                <Controller
                  name="education_level_id"
                  control={control}
                  render={({ field }) => (
                    <SearchSelect<ICatalogItem>
                      id="caregiver-education_level_id"
                      value={field.value || null}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.EDUCATION_LEVEL] ?? []}
                      isDisabled={isSaving}
                      isLoading={isLoadingCatalogs}
                      invalid={Boolean(errors.education_level_id)}
                      placeholder="Seleccionar…"
                    />
                  )}
                />
                <FieldError>{errors.education_level_id?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-4">
                <FieldLabel htmlFor="caregiver-occupation_id" required>
                  Ocupación
                </FieldLabel>
                <Controller
                  name="occupation_id"
                  control={control}
                  render={({ field }) => (
                    <SearchSelect<ICatalogItem>
                      id="caregiver-occupation_id"
                      value={field.value || null}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.OCCUPATION] ?? []}
                      isDisabled={isSaving}
                      isLoading={isLoadingCatalogs}
                      invalid={Boolean(errors.occupation_id)}
                      placeholder="Seleccionar…"
                    />
                  )}
                />
                <FieldError>{errors.occupation_id?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-4">
                <FieldLabel htmlFor="caregiver-population_type_id" required>
                  Tipo de población
                </FieldLabel>
                <Controller
                  name="population_type_id"
                  control={control}
                  render={({ field }) => (
                    <SearchSelect<ICatalogItem>
                      id="caregiver-population_type_id"
                      value={field.value || null}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.POPULATION_TYPE] ?? []}
                      isDisabled={isSaving}
                      isLoading={isLoadingCatalogs}
                      invalid={Boolean(errors.population_type_id)}
                      placeholder="Seleccionar…"
                    />
                  )}
                />
                <FieldError>{errors.population_type_id?.message}</FieldError>
              </Field>
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="caregiver-has_social_program">Recibe programa social</FieldLabel>
                <Controller
                  name="has_social_program"
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id="caregiver-has_social_program"
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>
            </div>
          </FieldGroup>
        </form>

        <SheetFooter className="shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} disabled={isSaving} className="min-w-[100px]">
            {isSaving ? 'Guardando…' : mode === CrudMode.EDIT ? 'Actualizar' : 'Crear'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
