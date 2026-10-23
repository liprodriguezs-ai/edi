import { PageLoader } from '@/shared/components/PageLoader'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { childFormSchema, type ChildFormValues, type IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { useChildStore } from '@/modules/child/store/childStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, type DefaultValues } from 'react-hook-form'
import { useNavigate, useParams } from 'react-router-dom'

/**
 * Formulario de alta/edición de niño (página completa, no modal — el
 * expediente clínico amerita más espacio que un Sheet). Navegar acá = "abrir
 * el form": los catálogos se piden al montar esta página, nunca en la lista.
 */
export function ChildFormPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const mode = id ? CrudMode.EDIT : CrudMode.CREATE

  const [entity, setEntity] = useState<IChild | null>(null)
  const [isLoadingEntity, setIsLoadingEntity] = useState(mode === CrudMode.EDIT)

  useEffect(() => {
    if (mode === CrudMode.EDIT && id) {
      setIsLoadingEntity(true)
      ChildResource.show(id)
        .then(setEntity)
        .finally(() => setIsLoadingEntity(false))
    }
  }, [mode, id])

  const crud = useBaseCrud<IChild, ChildFormValues>({
    store: useChildStore,
    resource: { save: ChildResource.create, update: ChildResource.update },
    mode,
    entityId: id,
    initialData: entity,
  })

  const defaultValues = useMemo<ChildFormValues>(
    () => ({
      first_name: entity?.first_name ?? '',
      last_name_paternal: entity?.last_name_paternal ?? '',
      last_name_maternal: entity?.last_name_maternal ?? '',
      birth_date: entity?.birth_date ?? '',
      sex_id: entity?.sex_id ?? '',
      curp: entity?.curp ?? '',
      external_id: entity?.external_id ?? '',
      health_center_id: entity?.health_center_id ?? '',
      is_schooled: entity?.is_schooled ?? false,
      notes: entity?.notes ?? '',
    }),
    [entity]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<ChildFormValues>({
    resolver: zodResolver(childFormSchema),
    defaultValues: defaultValues as DefaultValues<ChildFormValues>,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs({
    [CatalogType.SEX]: { _sort: 'name' },
    [CatalogType.HEALTH_CENTER]: { _sort: 'name' },
  })

  const isSaving = crud.store.isSaving

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      navigate(`/child/${result.id}`)
    }
  })

  if (isLoadingEntity) {
    return <PageLoader />
  }

  return (
    <div className="max-w-4xl space-y-6">
      <PageHeader
        title={mode === CrudMode.EDIT ? 'Editar niño' : 'Nuevo niño'}
        description="Datos generales del expediente"
      />

      <form onSubmit={(e) => void doSubmit(e)}>
        <RequiredFieldsLegend />
            <FieldGroup>
              <div className="grid grid-cols-12 gap-4">
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-first_name" required>
                    Nombre(s)
                  </FieldLabel>
                  <Input id="child-first_name" disabled={isSaving} {...register('first_name')} />
                  <FieldError>{errors.first_name?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6" />
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-last_name_paternal" required>
                    Apellido paterno
                  </FieldLabel>
                  <Input
                    id="child-last_name_paternal"
                    disabled={isSaving}
                    {...register('last_name_paternal')}
                  />
                  <FieldError>{errors.last_name_paternal?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-last_name_maternal">
                    Apellido materno
                  </FieldLabel>
                  <Input
                    id="child-last_name_maternal"
                    placeholder="Opcional"
                    disabled={isSaving}
                    {...register('last_name_maternal')}
                  />
                  <FieldError>{errors.last_name_maternal?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-birth_date" required>
                    Fecha de nacimiento
                  </FieldLabel>
                  <Input
                    id="child-birth_date"
                    type="date"
                    disabled={isSaving}
                    {...register('birth_date')}
                  />
                  <FieldError>{errors.birth_date?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-sex_id" required>
                    Sexo
                  </FieldLabel>
                  <Controller
                    name="sex_id"
                    control={control}
                    render={({ field }) => (
                      <SearchSelect<ICatalogItem>
                        id="child-sex_id"
                        value={field.value || null}
                        onChange={(v) => field.onChange((v as string | null) ?? '')}
                        options={catalogs[CatalogType.SEX] ?? []}
                        isDisabled={isSaving}
                        isLoading={isLoadingCatalogs}
                        invalid={Boolean(errors.sex_id)}
                        placeholder="Seleccionar…"
                      />
                    )}
                  />
                  <FieldError>{errors.sex_id?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-curp">CURP</FieldLabel>
                  <Input
                    id="child-curp"
                    className="font-mono"
                    placeholder="GASO180514MDFRPN09"
                    disabled={isSaving}
                    {...register('curp', {
                      onChange: (e) => {
                        e.target.value = e.target.value.toUpperCase()
                      },
                    })}
                  />
                  <FieldError>{errors.curp?.message}</FieldError>
                  {entity?.curp_mismatch && (
                    <p className="text-xs text-primary">
                      El CURP no coincide con la fecha de nacimiento o sexo capturados — revisa el dato.
                    </p>
                  )}
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-external_id">Expediente (sistema del centro)</FieldLabel>
                  <Input id="child-external_id" disabled={isSaving} {...register('external_id')} />
                  <FieldError>{errors.external_id?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-health_center_id" required>
                    Centro de salud
                  </FieldLabel>
                  <Controller
                    name="health_center_id"
                    control={control}
                    render={({ field }) => (
                      <SearchSelect<ICatalogItem>
                        id="child-health_center_id"
                        value={field.value || null}
                        onChange={(v) => field.onChange((v as string | null) ?? '')}
                        options={catalogs[CatalogType.HEALTH_CENTER] ?? []}
                        isDisabled={isSaving}
                        isLoading={isLoadingCatalogs}
                        invalid={Boolean(errors.health_center_id)}
                        placeholder="Seleccionar…"
                      />
                    )}
                  />
                  <FieldError>{errors.health_center_id?.message}</FieldError>
                </Field>
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="child-is_schooled">Asiste a escuela/guardería</FieldLabel>
                  <Controller
                    name="is_schooled"
                    control={control}
                    render={({ field }) => (
                      <Switch
                        id="child-is_schooled"
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        disabled={isSaving}
                      />
                    )}
                  />
                </Field>
                <Field className="col-span-12">
                  <FieldLabel htmlFor="child-notes">Notas</FieldLabel>
                  <Textarea id="child-notes" rows={3} disabled={isSaving} {...register('notes')} />
                  <FieldError>{errors.notes?.message}</FieldError>
                </Field>
              </div>
            </FieldGroup>

            <div className="mt-6 flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => navigate(-1)} disabled={isSaving}>
                Cancelar
              </Button>
              <Button type="submit" loading={isSaving}>
                {isSaving ? 'Guardando…' : mode === CrudMode.EDIT ? 'Actualizar' : 'Crear'}
              </Button>
            </div>
      </form>
    </div>
  )
}
