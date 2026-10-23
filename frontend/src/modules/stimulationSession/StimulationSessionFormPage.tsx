import { PageLoader } from '@/shared/components/PageLoader'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Textarea } from '@/components/ui/textarea'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import {
  buildSessionPayload,
  stimulationSessionFormSchema,
  type IStimulationSession,
  type StimulationSessionFormValues,
} from '@/modules/stimulationSession/schema'
import { CollapsibleSection, SuggestField } from '@/modules/stimulationSession/components/FormHelpers'
import { MassageGrid } from '@/modules/stimulationSession/components/MassageGrid'
import { StimulationSessionResource } from '@/modules/stimulationSession/services/StimulationSessionResource'
import { useStimulationSessionStore } from '@/modules/stimulationSession/store/stimulationSessionStore'
import { childFullName } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import type { IChild } from '@/modules/child/schema'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import type { IVisit } from '@/modules/visit/schema'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, useFieldArray, type DefaultValues } from 'react-hook-form'
import { useNavigate, useParams } from 'react-router-dom'
import { todayLocalDateString } from '@/shared/utils/dateHelper'

/**
 * REQ-SS-001/002/003 — session form: subject selector (individual child via
 * async search on the child list endpoint, D12; or group), four collapsible
 * sections (integración, preparación con grid de masaje, actividad central,
 * relajación). Catalogs load on mount of THIS page only (form-open, never
 * list scope).
 */
export function StimulationSessionFormPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const mode = id ? CrudMode.EDIT : CrudMode.CREATE

  const [entity, setEntity] = useState<IStimulationSession | null>(null)
  const [isLoadingEntity, setIsLoadingEntity] = useState(mode === CrudMode.EDIT)
  const [selectedChild, setSelectedChild] = useState<IChild | null>(null)
  const [childVisits, setChildVisits] = useState<IVisit[]>([])

  useEffect(() => {
    if (mode === CrudMode.EDIT && id) {
      setIsLoadingEntity(true)
      const includeParams = {
        include: 'child,stimulation_group,practitioner,movements,relaxation_zones',
      } as unknown as Partial<StimulationSessionFormValues>
      StimulationSessionResource.show(id, includeParams)
        .then((data) => {
          setEntity(data)
          if (data.child) setSelectedChild(data.child as unknown as IChild)
        })
        .finally(() => setIsLoadingEntity(false))
    }
  }, [mode, id])

  const crud = useBaseCrud<IStimulationSession, StimulationSessionFormValues>({
    store: useStimulationSessionStore,
    resource: {
      save: (data) => StimulationSessionResource.create(buildSessionPayload(data as StimulationSessionFormValues) as never),
      update: (entityId, data) =>
        StimulationSessionResource.update(entityId, buildSessionPayload(data as StimulationSessionFormValues) as never),
    },
    mode,
    entityId: id,
    initialData: entity,
  })

  const defaultValues = useMemo<StimulationSessionFormValues>(
    () => ({
      subject_type: entity?.stimulation_group_id ? 'group' : 'child',
      child_id: entity?.child_id ?? '',
      stimulation_group_id: entity?.stimulation_group_id ?? '',
      visit_id: entity?.visit_id ?? '',
      session_date: entity?.session_date ?? todayLocalDateString(),
      practitioner_id: entity?.practitioner_id ?? '',
      integration_dynamic_id: entity?.integration_dynamic_id ?? '',
      integration_material: entity?.integration_material ?? '',
      integration_song: entity?.integration_song ?? '',
      preparation_song: entity?.preparation_song ?? '',
      core_stimulation_area_id: entity?.core_stimulation_area_id ?? '',
      core_dynamic_id: entity?.core_dynamic_id ?? '',
      core_activity: entity?.core_activity ?? '',
      core_material: entity?.core_material ?? '',
      core_song: entity?.core_song ?? '',
      relaxation_notes: entity?.relaxation_notes ?? '',
      observations: entity?.observations ?? '',
      movements: entity?.movements ?? [],
      relaxation_zones: entity?.relaxation_zones ?? [],
    }),
    [entity]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    formState: { errors },
  } = useForm<StimulationSessionFormValues>({
    resolver: zodResolver(stimulationSessionFormSchema),
    defaultValues: defaultValues as DefaultValues<StimulationSessionFormValues>,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const movementsField = useFieldArray({ control, name: 'movements' })
  const relaxationField = useFieldArray({ control, name: 'relaxation_zones' })

  const subjectType = watch('subject_type')
  const childId = watch('child_id')

  useEffect(() => {
    if (subjectType !== 'child' || !childId) {
      setChildVisits([])
      return
    }
    VisitResource.search({
      filters: [{ field: 'child_id', operator: '=', value: childId }],
      sort: [{ field: 'visit_date', direction: 'desc' }],
    })
      .then((response) => setChildVisits(response.data))
      .catch(() => setChildVisits([]))
  }, [subjectType, childId])

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs({
    [CatalogType.PRACTITIONER_PROFILE]: { _with: 'user' },
    [CatalogType.STIMULATION_GROUP]: { _sort: 'name' },
    [CatalogType.STIMULATION_DYNAMIC]: { _sort: 'name', _filters: { is_active: true } },
    [CatalogType.BODY_ZONE]: { _sort: 'name', _filters: { is_active: true } },
    [CatalogType.MASSAGE_MOVEMENT]: { _sort: 'name', _filters: { is_active: true } },
    [CatalogType.DIDACTIC_MATERIAL]: { _sort: 'name', _filters: { is_active: true } },
    [CatalogType.SONG]: { _sort: 'name', _filters: { is_active: true } },
    [CatalogType.STIMULATION_AREA]: { _sort: 'name' },
  })

  const bodyZones = catalogs[CatalogType.BODY_ZONE] ?? []
  const massageMovements = catalogs[CatalogType.MASSAGE_MOVEMENT] ?? []
  const materialOptions = catalogs[CatalogType.DIDACTIC_MATERIAL] ?? []
  const songOptions = catalogs[CatalogType.SONG] ?? []

  const isSaving = crud.store.isSaving

  const hasMovement = (bodyZoneId: string, movementId: string) =>
    movementsField.fields.some((f) => f.body_zone_id === bodyZoneId && f.massage_movement_id === movementId)

  const toggleMovement = (bodyZoneId: string, movementId: string) => {
    const index = movementsField.fields.findIndex(
      (f) => f.body_zone_id === bodyZoneId && f.massage_movement_id === movementId
    )
    if (index >= 0) {
      movementsField.remove(index)
    } else {
      movementsField.append({ body_zone_id: bodyZoneId, massage_movement_id: movementId })
    }
  }

  const hasRelaxationZone = (bodyZoneId: string) => relaxationField.fields.some((f) => f.body_zone_id === bodyZoneId)

  const toggleRelaxationZone = (bodyZoneId: string) => {
    const index = relaxationField.fields.findIndex((f) => f.body_zone_id === bodyZoneId)
    if (index >= 0) {
      relaxationField.remove(index)
    } else {
      relaxationField.append({ body_zone_id: bodyZoneId })
    }
  }

  const doSubmit = handleSubmit(async (values) => {
    try {
      const result = await crud.submit(values)
      if (result) {
        navigate('/stimulation-session')
      }
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      }
    }
  })

  if (isLoadingEntity) {
    return <PageLoader />
  }

  return (
    <div className="max-w-4xl space-y-6">
      <PageHeader
        title={mode === CrudMode.EDIT ? 'Editar sesión de estimulación' : 'Nueva sesión de estimulación'}
        description="Registra la sesión con sus cuatro secciones oficiales"
      />

      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <RequiredFieldsLegend />

        <div className="border border-border">
          <section className="px-4 py-4">
            <h3 className="mb-4 text-sm font-semibold">Sujeto y datos generales</h3>
            <FieldGroup>
              <div className="grid grid-cols-12 gap-4">
                <Field className="col-span-12">
                  <FieldLabel required>Tipo de sesión</FieldLabel>
                  <Controller
                    name="subject_type"
                    control={control}
                    render={({ field }) => (
                      <RadioGroup
                        value={field.value}
                        onValueChange={field.onChange}
                        className="flex gap-6"
                        disabled={isSaving}
                      >
                        <label className="flex items-center gap-2 text-sm">
                          <RadioGroupItem value="child" /> Individual
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                          <RadioGroupItem value="group" /> Grupal
                        </label>
                      </RadioGroup>
                    )}
                  />
                </Field>

                {subjectType === 'child' ? (
                  <>
                    <Field className="col-span-12 sm:col-span-6">
                      <FieldLabel htmlFor="session-child_id" required>
                        Niño
                      </FieldLabel>
                      <Controller
                        name="child_id"
                        control={control}
                        render={({ field }) => (
                          <SearchSelect<IChild>
                            id="session-child_id"
                            value={field.value || null}
                            onChange={(v) => field.onChange((v as string | null) ?? '')}
                            loadOptions={(term) => ChildResource.searchByTerm(term)}
                            selectedOptions={selectedChild ? [selectedChild] : []}
                            getOptionLabel={(c) => childFullName(c)}
                            isDisabled={isSaving}
                            invalid={Boolean(errors.child_id)}
                            placeholder="Buscar niño…"
                          />
                        )}
                      />
                      <FieldError>{errors.child_id?.message}</FieldError>
                    </Field>
                    <Field className="col-span-12 sm:col-span-6">
                      <FieldLabel htmlFor="session-visit_id">Visita relacionada (opcional)</FieldLabel>
                      <Controller
                        name="visit_id"
                        control={control}
                        render={({ field }) => (
                          <SearchSelect<ICatalogItem>
                            id="session-visit_id"
                            value={field.value || null}
                            onChange={(v) => field.onChange((v as string | null) ?? '')}
                            options={childVisits.map((v) => ({ id: v.id, name: v.receipt_number }))}
                            isClearable
                            isDisabled={isSaving || !childId}
                            placeholder={childId ? 'Sin visita' : 'Selecciona un niño primero'}
                          />
                        )}
                      />
                    </Field>
                  </>
                ) : (
                  <Field className="col-span-12 sm:col-span-6">
                    <FieldLabel htmlFor="session-stimulation_group_id" required>
                      Grupo
                    </FieldLabel>
                    <Controller
                      name="stimulation_group_id"
                      control={control}
                      render={({ field }) => (
                        <SearchSelect<ICatalogItem>
                          id="session-stimulation_group_id"
                          value={field.value || null}
                          onChange={(v) => field.onChange((v as string | null) ?? '')}
                          options={catalogs[CatalogType.STIMULATION_GROUP] ?? []}
                          isLoading={isLoadingCatalogs}
                          isDisabled={isSaving}
                          invalid={Boolean(errors.child_id)}
                          placeholder="Seleccionar…"
                        />
                      )}
                    />
                    <FieldError>{errors.child_id?.message}</FieldError>
                  </Field>
                )}

                <Field className="col-span-12 sm:col-span-6">
                  <FieldLabel htmlFor="session-session_date" required>
                    Fecha de la sesión
                  </FieldLabel>
                  <Input
                    id="session-session_date"
                    type="date"
                    className="font-mono tabular-nums"
                    disabled={isSaving}
                    max={todayLocalDateString()}
                    {...register('session_date')}
                  />
                  <FieldError>{errors.session_date?.message}</FieldError>
                </Field>

                <Field className="col-span-12 sm:col-span-6">
                  <FieldLabel htmlFor="session-practitioner_id" required>
                    Profesional
                  </FieldLabel>
                  <Controller
                    name="practitioner_id"
                    control={control}
                    render={({ field }) => (
                      <SearchSelect<ICatalogItem>
                        id="session-practitioner_id"
                        value={field.value || null}
                        onChange={(v) => field.onChange((v as string | null) ?? '')}
                        options={catalogs[CatalogType.PRACTITIONER_PROFILE] ?? []}
                        isLoading={isLoadingCatalogs}
                        isDisabled={isSaving}
                        invalid={Boolean(errors.practitioner_id)}
                        getOptionLabel={(o) => (o as { user?: { name?: string } }).user?.name || o.name || ''}
                        placeholder="Seleccionar…"
                      />
                    )}
                  />
                  <FieldError>{errors.practitioner_id?.message}</FieldError>
                </Field>
              </div>
            </FieldGroup>
          </section>

        <CollapsibleSection title="Integración" defaultOpen>
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="session-integration_dynamic_id">Dinámica</FieldLabel>
              <Controller
                name="integration_dynamic_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="session-integration_dynamic_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.STIMULATION_DYNAMIC] ?? []}
                    isLoading={isLoadingCatalogs}
                    isClearable
                    isDisabled={isSaving}
                    placeholder="Seleccionar…"
                  />
                )}
              />
            </Field>
            <SuggestField
              id="session-integration_material"
              label="Material"
              register={register('integration_material')}
              suggestions={materialOptions}
              disabled={isSaving}
            />
            <SuggestField
              id="session-integration_song"
              label="Canción"
              register={register('integration_song')}
              suggestions={songOptions}
              disabled={isSaving}
            />
          </div>
        </CollapsibleSection>

        <CollapsibleSection title="Preparación (masaje)">
          <div className="space-y-4">
            <SuggestField
              id="session-preparation_song"
              label="Canción"
              register={register('preparation_song')}
              suggestions={songOptions}
              disabled={isSaving}
            />
            <MassageGrid
              bodyZones={bodyZones}
              massageMovements={massageMovements}
              hasMovement={hasMovement}
              toggleMovement={toggleMovement}
              disabled={isSaving}
            />
            <FieldError>{errors.movements?.message}</FieldError>
          </div>
        </CollapsibleSection>

        <CollapsibleSection title="Actividad central">
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="session-core_stimulation_area_id">Área de estimulación</FieldLabel>
              <Controller
                name="core_stimulation_area_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="session-core_stimulation_area_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.STIMULATION_AREA] ?? []}
                    isLoading={isLoadingCatalogs}
                    isClearable
                    isDisabled={isSaving}
                    placeholder="Seleccionar…"
                  />
                )}
              />
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="session-core_dynamic_id">Dinámica</FieldLabel>
              <Controller
                name="core_dynamic_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="session-core_dynamic_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.STIMULATION_DYNAMIC] ?? []}
                    isLoading={isLoadingCatalogs}
                    isClearable
                    isDisabled={isSaving}
                    placeholder="Seleccionar…"
                  />
                )}
              />
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="session-core_activity">Descripción de la actividad</FieldLabel>
              <Textarea id="session-core_activity" disabled={isSaving} {...register('core_activity')} />
            </Field>
            <SuggestField
              id="session-core_material"
              label="Material"
              register={register('core_material')}
              suggestions={materialOptions}
              disabled={isSaving}
            />
            <SuggestField
              id="session-core_song"
              label="Canción"
              register={register('core_song')}
              suggestions={songOptions}
              disabled={isSaving}
            />
          </div>
        </CollapsibleSection>

        <CollapsibleSection title="Relajación">
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12">
              <FieldLabel>Zonas relajadas</FieldLabel>
              <div className="flex flex-wrap gap-3">
                {bodyZones.map((zone) => (
                  <label key={zone.id} className="flex items-center gap-2 text-sm">
                    <Checkbox
                      checked={hasRelaxationZone(zone.id)}
                      onCheckedChange={() => toggleRelaxationZone(zone.id)}
                      disabled={isSaving}
                    />
                    {zone.name}
                  </label>
                ))}
              </div>
              <FieldError>{errors.relaxation_zones?.message}</FieldError>
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="session-relaxation_notes">Notas</FieldLabel>
              <Textarea id="session-relaxation_notes" disabled={isSaving} {...register('relaxation_notes')} />
            </Field>
          </div>
        </CollapsibleSection>

          <section className="border-t border-border px-4 py-6">
            <Field>
              <FieldLabel htmlFor="session-observations">Observaciones generales</FieldLabel>
              <Textarea id="session-observations" disabled={isSaving} {...register('observations')} />
            </Field>
          </section>
        </div>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => navigate('/stimulation-session')} disabled={isSaving}>
            Cancelar
          </Button>
          <Button type="submit" loading={isSaving}>
            {isSaving ? 'Guardando…' : 'Guardar'}
          </Button>
        </div>
      </form>
    </div>
  )
}
