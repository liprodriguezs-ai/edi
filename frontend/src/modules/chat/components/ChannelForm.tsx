import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  buildChannelFormDefaults,
  buildChannelTargetSelector,
  buildChannelWriteTargetSelector,
  channelFormSchema,
  CHANNEL_AVAILABLE_TARGET_TYPES,
  CHANNEL_TARGET_TYPE_LABELS,
  type ChannelFormValues,
  type IChatChannel,
} from '@/modules/chat/schema'
import { ChatChannelResource } from '@/modules/chat/services/ChatResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import {
  SingleTargetSelector,
  type SingleTargetNames,
} from '@/shared/components/target/SingleTargetSelector'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'

// `as const satisfies SingleTargetNames` conserva los tipos LITERAL de cada nombre (el
// `satisfies` solo, contra una interfaz de campos `string`, los ensancharía a `string`),
// así `setValue(READ_TARGET_NAMES.unitId, …)` infiere el tipo del valor y queda atado a la
// constante: renombrar una clave acá actualiza el callback sin romper en silencio.

/** Nombres RHF del bloque de LECTURA (¿quién ve/recibe?) — prefijo `target_*`. */
const READ_TARGET_NAMES = {
  targetType: 'target_type',
  unitTypeIds: 'target_unit_type_ids',
  towerIds: 'target_tower_ids',
  sectionIds: 'target_section_ids',
  featureIds: 'target_unit_feature_ids',
  floor: 'target_floors',
  unitId: 'target_unit_id',
  unitIds: 'target_unit_ids',
} as const satisfies SingleTargetNames

/** Nombres RHF del bloque de ESCRITURA (¿quién puede postear?) — prefijo `write_target_*`. */
const WRITE_TARGET_NAMES = {
  targetType: 'write_target_type',
  unitTypeIds: 'write_target_unit_type_ids',
  towerIds: 'write_target_tower_ids',
  sectionIds: 'write_target_section_ids',
  featureIds: 'write_target_unit_feature_ids',
  floor: 'write_target_floors',
  unitId: 'write_target_unit_id',
  unitIds: 'write_target_unit_ids',
} as const satisfies SingleTargetNames

// Referencia estable para los fallbacks de arrays de unidad: evita crear un `[]` nuevo en cada
// render (rompería la memoización aguas abajo del selector compartido).
const EMPTY: string[] = []

interface ChannelFormProps {
  /** Canal a editar. Ausente = alta. */
  channel?: IChatChannel | null
  /** Otros canales ofrecidos como fuente de "copiar config" (lista ligera, cargada lazy). */
  channels: IChatChannel[]
  /** Se dispara la 1ª vez que se abre el dropdown "Copiar config de…" (carga lazy en la page). */
  onCopySourcesOpen?: () => void
  /** La lista de copy-sources se está cargando (muestra spinner en el SearchSelect). */
  copySourcesLoading?: boolean
  /** La query de copy-sources ya resolvió: permite ocultar el campo si quedó vacía. */
  copySourcesLoaded?: boolean
  /** Se llama tras un guardado exitoso (crear o actualizar). */
  onSaved: () => void
  onCancel: () => void
}

/**
 * Formulario de alta/edición de un canal del condominio (Fase 2), pensado para vivir en una
 * PAGE (sin el chrome del Dialog): nombre, tipo, icono, alcance de lectura (quién ve/recibe),
 * alcance de escritura (quién puede postear) y notificar-al-postear. Permite copiar la config
 * de otro canal. Los botones de acción viven al pie, dentro del form.
 */
export function ChannelForm({
  channel,
  channels,
  onCopySourcesOpen,
  copySourcesLoading = false,
  copySourcesLoaded = false,
  onSaved,
  onCancel,
}: ChannelFormProps) {
  const isEdit = Boolean(channel?.id)

  // Esta es una PAGE dedicada al form: navegar hasta el editor ES "abrir el formulario", así
  // que cargar los catálogos al montar es el patrón load-on-form-open (§2.12), NO la carga
  // eager de una lista — por eso ya no hace falta el gate `enabled`.
  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.CHAT_CHANNEL_TYPE]: { _sort: 'order' },
        [CatalogType.UNIT_TYPE]: { _sort: 'name' },
        [CatalogType.TOWER]: { _sort: 'name' },
        [CatalogType.SECTION]: { _sort: 'name' },
        [CatalogType.UNIT_FEATURE]: { _sort: 'name' },
      }),
      []
    )
  )

  const defaultValues = useMemo(() => buildChannelFormDefaults(channel), [channel])

  const {
    register,
    handleSubmit,
    control,
    reset,
    setValue,
    formState: { errors },
  } = useForm<ChannelFormValues>({
    resolver: zodResolver(channelFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const targetType = useWatch({ control, name: 'target_type' })
  const writeTargetType = useWatch({ control, name: 'write_target_type' })
  // Valores de unidad de cada bloque (single_unit legacy + manual): se leen con useWatch
  // para alimentar el selector compartido sin romper la memoización del compilador.
  const readUnitId = useWatch({ control, name: 'target_unit_id' })
  const readUnitIds = useWatch({ control, name: 'target_unit_ids' })
  const writeUnitId = useWatch({ control, name: 'write_target_unit_id' })
  const writeUnitIds = useWatch({ control, name: 'write_target_unit_ids' })

  // Labels visibles de la unidad elegida en la rama legacy `single_unit` (read / write).
  const [readUnitLabel, setReadUnitLabel] = useState('')
  const [writeUnitLabel, setWriteUnitLabel] = useState('')

  // Catálogos que alimentan los dropdowns de alcance (compartido por ambos bloques).
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
    mutationFn: (values: ChannelFormValues) => {
      const payload = {
        name: values.name,
        chat_channel_type_id: values.chat_channel_type_id,
        icon: values.icon || null,
        description: values.description || null,
        target_type: values.target_type,
        target_selector: buildChannelTargetSelector(values),
        write_target_type: values.write_target_type,
        write_target_selector: buildChannelWriteTargetSelector(values),
        notify_on_post: values.notify_on_post,
      }
      return isEdit && channel?.id
        ? ChatChannelResource.update(channel.id, payload)
        : ChatChannelResource.create(payload)
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Canal actualizado' : 'Canal creado')
      onSaved()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo guardar el canal'
      ),
  })

  // Copiar ambos alcances (lectura + escritura) de otro canal hacia este formulario.
  const copyFrom = (sourceId: string) => {
    const source = channels.find((c) => c.id === sourceId)
    if (!source) return
    const sourceDefaults = buildChannelFormDefaults(source)
    // Read target
    setValue('target_type', sourceDefaults.target_type, { shouldDirty: true })
    setValue('target_unit_type_ids', sourceDefaults.target_unit_type_ids, { shouldDirty: true })
    setValue('target_tower_ids', sourceDefaults.target_tower_ids, { shouldDirty: true })
    setValue('target_section_ids', sourceDefaults.target_section_ids, { shouldDirty: true })
    setValue('target_floors', sourceDefaults.target_floors, { shouldDirty: true })
    setValue('target_unit_feature_ids', sourceDefaults.target_unit_feature_ids, {
      shouldDirty: true,
    })
    setValue('target_unit_id', sourceDefaults.target_unit_id, { shouldDirty: true })
    setValue('target_unit_ids', sourceDefaults.target_unit_ids, { shouldDirty: true })
    // Write target
    setValue('write_target_type', sourceDefaults.write_target_type, { shouldDirty: true })
    setValue('write_target_unit_type_ids', sourceDefaults.write_target_unit_type_ids, {
      shouldDirty: true,
    })
    setValue('write_target_tower_ids', sourceDefaults.write_target_tower_ids, { shouldDirty: true })
    setValue('write_target_section_ids', sourceDefaults.write_target_section_ids, {
      shouldDirty: true,
    })
    setValue('write_target_floors', sourceDefaults.write_target_floors, { shouldDirty: true })
    setValue('write_target_unit_feature_ids', sourceDefaults.write_target_unit_feature_ids, {
      shouldDirty: true,
    })
    setValue('write_target_unit_id', sourceDefaults.write_target_unit_id, { shouldDirty: true })
    setValue('write_target_unit_ids', sourceDefaults.write_target_unit_ids, { shouldDirty: true })
    toast.info(`Config copiada de "${source.name}"`)
  }

  const copySources = useMemo(
    () => channels.filter((c) => c.id !== channel?.id),
    [channels, channel]
  )

  // El campo se muestra hasta que SE SEPA que no hay fuentes: antes de cargar (para poder abrir
  // el dropdown y disparar la carga lazy) y si la lista trajo canales; solo se oculta cuando la
  // query ya resolvió y quedó vacía.
  const showCopyField = !copySourcesLoaded || copySources.length > 0

  return (
    <form
      onSubmit={handleSubmit((values) => mutation.mutate(values))}
      className='rounded-[16px] border border-border bg-card p-6 shadow-sm md:p-8'
    >
      <FieldGroup>
        <RequiredFieldsLegend />

        {/* Datos básicos: aprovechan el ancho de la página en un grid de 12 columnas. */}
        <div className='grid grid-cols-12 gap-x-5 gap-y-6'>
          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel htmlFor='channel-name' required>
              Nombre
            </FieldLabel>
            <Input
              id='channel-name'
              placeholder='Vecinos · Deportes…'
              {...register('name')}
              disabled={mutation.isPending}
            />
            <FieldError>{errors.name?.message}</FieldError>
          </Field>

          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel htmlFor='channel-type' required>
              Tipo de canal
            </FieldLabel>
            <Controller
              name='chat_channel_type_id'
              control={control}
              render={({ field }) => (
                <SearchSelect
                  id='channel-type'
                  value={field.value}
                  onChange={(v) => field.onChange((v as string | null) ?? '')}
                  options={catalogs[CatalogType.CHAT_CHANNEL_TYPE] ?? []}
                  isDisabled={mutation.isPending}
                  invalid={!!errors.chat_channel_type_id}
                  placeholder='Seleccionar…'
                />
              )}
            />
            <FieldError>{errors.chat_channel_type_id?.message}</FieldError>
          </Field>

          <Field className='col-span-12 md:col-span-3'>
            <FieldLabel htmlFor='channel-icon'>Icono</FieldLabel>
            <Input
              id='channel-icon'
              placeholder='💬'
              {...register('icon')}
              disabled={mutation.isPending}
            />
          </Field>

          <Field className='col-span-12 md:col-span-9'>
            <FieldLabel htmlFor='channel-desc'>Descripción</FieldLabel>
            <Textarea
              id='channel-desc'
              rows={2}
              className='resize-none'
              {...register('description')}
              disabled={mutation.isPending}
            />
          </Field>

          {showCopyField && (
            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='channel-copy'>Copiar config de…</FieldLabel>
              <SearchSelect
                id='channel-copy'
                value={null}
                onChange={(v) => {
                  if (v) copyFrom(v as string)
                }}
                onMenuOpen={onCopySourcesOpen}
                options={copySources}
                isLoading={copySourcesLoading}
                isDisabled={mutation.isPending}
                placeholder='Elegir un canal para copiar alcances'
              />
              <FieldDescription>
                Copia el alcance de lectura y escritura del canal elegido.
              </FieldDescription>
            </Field>
          )}
        </div>

        {/* READ target — ¿quiénes ven y reciben el canal? */}
        <SingleTargetSelector
          control={control}
          register={register}
          errors={errors}
          names={READ_TARGET_NAMES}
          sectionLabel='Alcance — ¿quiénes ven y reciben?'
          availableTypes={CHANNEL_AVAILABLE_TARGET_TYPES}
          typeLabels={CHANNEL_TARGET_TYPE_LABELS}
          targetType={targetType}
          targetUnitId={readUnitId}
          targetUnitIds={readUnitIds ?? EMPTY}
          catalogs={targetCatalogs}
          disabled={mutation.isPending}
          idPrefix='read'
          floorIsMulti
          singleUnitLabel={readUnitLabel}
          onSingleUnitLabelChange={setReadUnitLabel}
          onUnitSelect={(id) => setValue(READ_TARGET_NAMES.unitId, id, { shouldValidate: true })}
          onUnitIdsChange={(ids) =>
            setValue(READ_TARGET_NAMES.unitIds, ids, { shouldValidate: true })
          }
        />

        {/* WRITE target — ¿quién puede escribir? */}
        <SingleTargetSelector
          control={control}
          register={register}
          errors={errors}
          names={WRITE_TARGET_NAMES}
          sectionLabel='¿Quién puede escribir?'
          availableTypes={CHANNEL_AVAILABLE_TARGET_TYPES}
          typeLabels={CHANNEL_TARGET_TYPE_LABELS}
          targetType={writeTargetType}
          targetUnitId={writeUnitId}
          targetUnitIds={writeUnitIds ?? EMPTY}
          catalogs={targetCatalogs}
          disabled={mutation.isPending}
          idPrefix='write'
          floorIsMulti
          singleUnitLabel={writeUnitLabel}
          onSingleUnitLabelChange={setWriteUnitLabel}
          onUnitSelect={(id) => setValue(WRITE_TARGET_NAMES.unitId, id, { shouldValidate: true })}
          onUnitIdsChange={(ids) =>
            setValue(WRITE_TARGET_NAMES.unitIds, ids, { shouldValidate: true })
          }
        />

        <Field orientation='horizontal' className='items-center justify-between'>
          <div>
            <FieldLabel htmlFor='channel-notify'>Notificar al postear</FieldLabel>
            <FieldDescription>
              Envía notificación cuando alguien publica en el canal.
            </FieldDescription>
          </div>
          <Controller
            name='notify_on_post'
            control={control}
            render={({ field }) => (
              <Switch
                id='channel-notify'
                checked={field.value}
                onCheckedChange={field.onChange}
                disabled={mutation.isPending}
              />
            )}
          />
        </Field>

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
            {mutation.isPending ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear canal'}
          </Button>
        </Field>
      </FieldGroup>
    </form>
  )
}
