import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  ANNOUNCEMENT_AVAILABLE_TARGET_TYPES,
  announcementFormSchema,
  buildAnnouncementFormDefaults,
  buildAnnouncementTargetSelector,
  type AnnouncementFormValues,
  type IAnnouncement,
} from '@/modules/announcement/schema'
import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { RichTextEditor } from '@/shared/components/richtext/RichTextEditor'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { SingleTargetSelector } from '@/shared/components/target/SingleTargetSelector'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import type { IMedia } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Paperclip, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'

const MAX_FILES = 5
const MAX_FILE_BYTES = 10 * 1024 * 1024

const CHANNELS = [
  ['channel_push', 'Push'],
  ['channel_board', 'Tablero'],
  ['channel_email', 'Correo'],
] as const

interface AnnouncementFormProps {
  mode: CrudMode
  /** Aviso a editar. Ausente = alta. */
  announcement?: IAnnouncement
  /** Se llama con el aviso creado/actualizado tras un guardado exitoso. */
  onSaved: (_result: IAnnouncement) => void
  onCancel: () => void
}

/**
 * Selector de adjuntos en ALTA: acumula archivos localmente (hasta 5 PDF/imagen, máx. 10 MB
 * cada uno) y se suben tras crear el aviso. En edición se usa `EditAttachmentsManager`.
 */
function AttachmentsField({
  files,
  onAdd,
  onRemove,
  disabled,
}: {
  files: File[]
  onAdd: (_picked: FileList | null) => void
  onRemove: (_index: number) => void
  disabled: boolean
}) {
  const fileInputRef = useRef<HTMLInputElement>(null)

  return (
    <Field>
      <FieldLabel>Adjuntos</FieldLabel>
      <FieldDescription>
        Hasta {MAX_FILES} archivos (PDF o imagen), máx. 10 MB cada uno.
      </FieldDescription>
      <input
        ref={fileInputRef}
        type='file'
        multiple
        accept='image/*,application/pdf'
        className='hidden'
        onChange={(e) => {
          onAdd(e.target.files)
          e.target.value = ''
        }}
        disabled={disabled}
      />
      <div className='flex flex-col gap-2'>
        <Button
          type='button'
          variant='outline'
          className='w-fit rounded-full'
          onClick={() => fileInputRef.current?.click()}
          disabled={disabled || files.length >= MAX_FILES}
        >
          <Paperclip className='size-4' />
          Agregar archivo
        </Button>
        {files.length > 0 && (
          <ul className='space-y-1.5'>
            {files.map((file, index) => (
              <li
                key={`${file.name}-${index}`}
                className='flex items-center justify-between gap-2 rounded-[10px] border border-border bg-[var(--surface-2)] px-3 py-1.5 text-[12.5px]'
              >
                <span className='truncate'>{file.name}</span>
                <button
                  type='button'
                  className='flex-none text-[var(--ink-4)] hover:text-destructive'
                  onClick={() => onRemove(index)}
                  disabled={disabled}
                  aria-label={`Quitar ${file.name}`}
                >
                  <X className='size-4' />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Field>
  )
}

/**
 * Gestor de adjuntos en EDICIÓN: las operaciones son INMEDIATAS contra el aviso ya
 * persistido. Lista los adjuntos existentes (`media`) con botón de quitar y permite subir
 * VARIOS archivos a la vez. Tras cada operación invalida el detalle del aviso para refrescar
 * la lista (el guardado por id del formulario evita perder cambios sin guardar del texto).
 */
function EditAttachmentsManager({
  announcementId,
  media,
  disabled,
}: {
  announcementId: string
  media: IMedia[]
  disabled: boolean
}) {
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: (mediaId: string) => AnnouncementResource.deleteMedia(announcementId, mediaId),
  })

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ['announcement', 'detail', announcementId] })

  const busy = disabled || uploading || deleteMutation.isPending
  const atLimit = media.length >= MAX_FILES

  const handleAdd = async (picked: FileList | null) => {
    if (!picked || picked.length === 0) return
    const incoming = Array.from(picked)
    const available = MAX_FILES - media.length
    if (available <= 0) {
      toast.error(`Máximo ${MAX_FILES} adjuntos por aviso`)
      return
    }
    const tooBig = incoming.find((f) => f.size > MAX_FILE_BYTES)
    if (tooBig) {
      toast.error(`"${tooBig.name}" supera el límite de 10 MB`)
      return
    }
    const toUpload = incoming.slice(0, available)
    if (incoming.length > available) {
      toast.error(`Solo caben ${available} adjunto(s) más — se subirán los primeros ${available}`)
    }
    setUploading(true)
    let failed = 0
    for (const file of toUpload) {
      try {
        await AnnouncementResource.uploadMedia(announcementId, file)
      } catch {
        failed += 1
      }
    }
    setUploading(false)
    await refresh()
    if (failed > 0) {
      toast.error(`${failed} ${failed === 1 ? 'adjunto no se subió' : 'adjuntos no se subieron'}`)
    } else {
      toast.success(toUpload.length === 1 ? 'Adjunto agregado' : 'Adjuntos agregados')
    }
  }

  const handleRemove = async (item: IMedia) => {
    try {
      await deleteMutation.mutateAsync(item.id)
      await refresh()
      toast.success('Adjunto eliminado')
    } catch {
      toast.error(`No se pudo eliminar ${item.name || item.file_name}`)
    }
  }

  return (
    <Field>
      <FieldLabel>Adjuntos</FieldLabel>
      <FieldDescription>
        Hasta {MAX_FILES} archivos (PDF o imagen), máx. 10 MB cada uno.
      </FieldDescription>
      <input
        ref={fileInputRef}
        type='file'
        multiple
        accept='image/*,application/pdf'
        className='hidden'
        onChange={(e) => {
          void handleAdd(e.target.files)
          e.target.value = ''
        }}
        disabled={busy}
      />
      <div className='flex flex-col gap-2'>
        <Button
          type='button'
          variant='outline'
          className='w-fit rounded-full'
          onClick={() => fileInputRef.current?.click()}
          disabled={busy || atLimit}
        >
          <Paperclip className='size-4' />
          {uploading ? 'Subiendo…' : 'Agregar archivo'}
        </Button>
        {media.length > 0 ? (
          <ul className='space-y-1.5'>
            {media.map((item) => (
              <li
                key={item.id}
                className='flex items-center justify-between gap-2 rounded-[10px] border border-border bg-[var(--surface-2)] px-3 py-1.5 text-[12.5px]'
              >
                <span className='truncate'>{item.name || item.file_name}</span>
                <button
                  type='button'
                  className='flex-none text-[var(--ink-4)] hover:text-destructive disabled:opacity-40'
                  onClick={() => void handleRemove(item)}
                  disabled={busy}
                  aria-label={`Quitar ${item.name || item.file_name}`}
                >
                  <X className='size-4' />
                </button>
              </li>
            ))}
          </ul>
        ) : (
          <p className='text-[12px] text-[var(--ink-4)]'>Sin adjuntos.</p>
        )}
      </div>
    </Field>
  )
}

/**
 * Formulario de alta/edición de un aviso (§12.3), pensado para vivir en una PAGE (sin el
 * chrome del Sheet). Contenido enriquecido (RichTextEditor) + tipo/prioridad + alcance +
 * canales + adjuntos. El HTML del editor viaja como `content_html`; el servidor lo sanitiza
 * y deriva el texto plano `content`.
 */
export function AnnouncementForm({ mode, announcement, onSaved, onCancel }: AnnouncementFormProps) {
  const isEdit = mode === CrudMode.EDIT
  const defaultValues = useMemo(() => buildAnnouncementFormDefaults(announcement), [announcement])

  const [files, setFiles] = useState<File[]>([])

  const {
    register,
    handleSubmit,
    reset,
    control,
    setValue,
    formState: { errors },
  } = useForm<AnnouncementFormValues>({
    resolver: zodResolver(announcementFormSchema),
    defaultValues,
  })

  // Siembra el form al montar (alta) o al cargar el aviso (edición). Guardamos por id para NO
  // re-sembrar cuando el aviso se refetchea al gestionar adjuntos — eso descartaría los
  // cambios sin guardar del título/contenido.
  const seededIdRef = useRef<string | null>(null)
  useEffect(() => {
    const nextId = announcement?.id ?? null
    if (seededIdRef.current === nextId) return
    seededIdRef.current = nextId
    reset(defaultValues)
  }, [announcement, defaultValues, reset])

  // Label visible de la unidad elegida en la rama legacy `single_unit` (oculta en el UI).
  const [singleUnitLabel, setSingleUnitLabel] = useState('')

  // Alcance en vivo: se suscribe SOLO a los campos de alcance vía useWatch para alimentar el
  // selector compartido + la vista previa de residentes.
  const targetFields = useWatch({
    control,
    name: [
      'target_type',
      'target_unit_type_ids',
      'target_tower_ids',
      'target_section_ids',
      'target_floor',
      'target_feature_ids',
      'target_unit_id',
      'target_unit_ids',
    ],
  })
  const [
    targetType,
    targetUnitTypeIds,
    targetTowerIds,
    targetSectionIds,
    targetFloor,
    targetFeatureIds,
    targetUnitId,
    targetUnitIds,
  ] = targetFields
  const previewSelector = useMemo(
    () =>
      buildAnnouncementTargetSelector({
        target_type: targetType,
        target_unit_type_ids: targetUnitTypeIds,
        target_tower_ids: targetTowerIds,
        target_section_ids: targetSectionIds,
        target_floor: targetFloor,
        target_feature_ids: targetFeatureIds,
        target_unit_id: targetUnitId,
        target_unit_ids: targetUnitIds,
      }),
    [
      targetType,
      targetUnitTypeIds,
      targetTowerIds,
      targetSectionIds,
      targetFloor,
      targetFeatureIds,
      targetUnitId,
      targetUnitIds,
    ]
  )

  const addFiles = (picked: FileList | null) => {
    if (!picked) return
    const incoming = Array.from(picked)
    const tooBig = incoming.find((f) => f.size > MAX_FILE_BYTES)
    if (tooBig) {
      toast.error(`"${tooBig.name}" supera el límite de 10 MB`)
      return
    }
    setFiles((prev) => {
      const merged = [...prev, ...incoming]
      if (merged.length > MAX_FILES) {
        toast.error(`Máximo ${MAX_FILES} adjuntos por aviso`)
        return merged.slice(0, MAX_FILES)
      }
      return merged
    })
  }

  // Catálogos del formulario. Esta es una PAGE dedicada al form: navegar hasta el editor ES
  // "abrir el formulario", así que cargar los catálogos al montar es el patrón load-on-form-open
  // (§2.12), NO la carga eager de una lista.
  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.ANNOUNCEMENT_TYPE]: { _sort: 'order' },
        [CatalogType.ANNOUNCEMENT_PRIORITY]: { _sort: 'order' },
        [CatalogType.UNIT_TYPE]: { _sort: 'name' },
        [CatalogType.TOWER]: { _sort: 'name' },
        [CatalogType.SECTION]: { _sort: 'name' },
        [CatalogType.UNIT_FEATURE]: { _sort: 'name' },
      }),
      []
    )
  )

  const mutation = useMutation({
    mutationFn: async (values: AnnouncementFormValues) => {
      // Payload explícito: enviamos `target_type` + `target_selector` en alta Y edición
      // (los campos internos `target_*` del form NO viajan). El contenido va como
      // `content_html` (HTML crudo del editor) — el servidor lo sanitiza y deriva `content`.
      const payload = {
        title: values.title,
        content_html: values.content_html,
        announcement_type_id: values.announcement_type_id,
        announcement_priority_id: values.announcement_priority_id,
        channel_push: values.channel_push,
        channel_board: values.channel_board,
        channel_email: values.channel_email,
        target_type: values.target_type,
        target_selector: buildAnnouncementTargetSelector(values),
        expires_at: values.expires_at || null,
      }
      if (isEdit && announcement) {
        const updated = await AnnouncementResource.update(announcement.id, payload)
        return { saved: updated, failedUploads: 0 }
      }
      const created = await AnnouncementResource.create(payload)
      // El aviso ya existe una vez que `create` resuelve. Los adjuntos se suben de uno en uno;
      // si alguno falla NO rechazamos la mutación (evita que el usuario reintente y duplique el
      // borrador). Contamos los fallidos y lo avisamos como advertencia no fatal.
      let failedUploads = 0
      for (const file of files) {
        try {
          await AnnouncementResource.uploadMedia(created.id, file)
        } catch {
          failedUploads += 1
        }
      }
      return { saved: created, failedUploads }
    },
    onSuccess: ({ saved, failedUploads }) => {
      if (isEdit) {
        toast.success('Aviso actualizado')
      } else if (failedUploads > 0) {
        toast.error(
          `Aviso creado, pero ${failedUploads} ${failedUploads === 1 ? 'adjunto no se subió' : 'adjuntos no se subieron'} — revísalo desde el detalle`
        )
      } else {
        toast.success(
          files.length > 0
            ? 'Aviso guardado con sus adjuntos — publícalo cuando esté listo'
            : 'Aviso guardado como borrador — publícalo cuando esté listo'
        )
      }
      onSaved(saved)
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo guardar el aviso'
      ),
  })

  const catalogSelect = (
    name: 'announcement_type_id' | 'announcement_priority_id',
    label: string,
    catalog: CatalogType
  ) => (
    <Field className='col-span-12 md:col-span-6'>
      <FieldLabel htmlFor={`announce-${name}`} required>
        {label}
      </FieldLabel>
      <Controller
        name={name}
        control={control}
        render={({ field }) => (
          <SearchSelect
            id={`announce-${name}`}
            value={field.value}
            onChange={(v) => field.onChange((v as string | null) ?? '')}
            options={catalogs[catalog] ?? []}
            isDisabled={mutation.isPending}
            invalid={!!errors[name]}
            placeholder='Seleccionar…'
          />
        )}
      />
      <FieldError>{errors[name]?.message}</FieldError>
    </Field>
  )

  return (
    <form
      onSubmit={handleSubmit((values) => mutation.mutate(values))}
      className='rounded-[16px] border border-border bg-card p-6 shadow-sm md:p-8'
    >
      <FieldGroup>
        <RequiredFieldsLegend />
        <div className='grid grid-cols-12 gap-x-5 gap-y-6'>
          <Field className='col-span-12'>
            <FieldLabel htmlFor='announce-title' required>
              Título
            </FieldLabel>
            <Input
              id='announce-title'
              placeholder='Corte de agua programado'
              {...register('title')}
              disabled={mutation.isPending}
            />
            <FieldError>{errors.title?.message}</FieldError>
          </Field>

          {catalogSelect('announcement_type_id', 'Tipo', CatalogType.ANNOUNCEMENT_TYPE)}
          {catalogSelect(
            'announcement_priority_id',
            'Prioridad',
            CatalogType.ANNOUNCEMENT_PRIORITY
          )}

          <Field className='col-span-12'>
            <FieldLabel id='announce-content-label' htmlFor='announce-content' required>
              Contenido
            </FieldLabel>
            <Controller
              name='content_html'
              control={control}
              render={({ field }) => (
                <RichTextEditor
                  id='announce-content'
                  ariaLabelledBy='announce-content-label'
                  value={field.value}
                  onChange={field.onChange}
                  disabled={mutation.isPending}
                  invalid={!!errors.content_html}
                />
              )}
            />
            <FieldDescription>Da formato con la barra: negrita, listas, enlaces…</FieldDescription>
            <FieldError>{errors.content_html?.message}</FieldError>
          </Field>

          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel htmlFor='announce-expires'>Expira</FieldLabel>
            <Input
              id='announce-expires'
              type='date'
              {...register('expires_at')}
              disabled={mutation.isPending}
            />
            <FieldDescription>Deja vacío si no expira</FieldDescription>
          </Field>
        </div>

        {/* Destinatarios — ¿quiénes reciben el aviso? Con vista previa en vivo de residentes.
            Editable incluso si el aviso ya está publicado. */}
        <SingleTargetSelector
          control={control}
          register={register}
          errors={errors}
          targetType={targetType}
          targetUnitId={targetUnitId}
          targetUnitIds={targetUnitIds ?? []}
          catalogs={{
            unitTypes: catalogs[CatalogType.UNIT_TYPE] ?? [],
            towers: catalogs[CatalogType.TOWER] ?? [],
            sections: catalogs[CatalogType.SECTION] ?? [],
            features: catalogs[CatalogType.UNIT_FEATURE] ?? [],
          }}
          disabled={mutation.isPending}
          idPrefix='announce'
          availableTypes={ANNOUNCEMENT_AVAILABLE_TARGET_TYPES}
          sectionLabel='Destinatarios — ¿quiénes lo reciben?'
          singleUnitLabel={singleUnitLabel}
          onSingleUnitLabelChange={setSingleUnitLabel}
          onUnitSelect={(id) => setValue('target_unit_id', id, { shouldValidate: true })}
          onUnitIdsChange={(ids) => setValue('target_unit_ids', ids, { shouldValidate: true })}
          previewScope='announcement'
          previewSelector={previewSelector}
          previewFetcher={AnnouncementResource.preview}
          previewNoun='residentes'
        />

        <Field>
          <FieldLabel>Canales de envío</FieldLabel>
          <div className='flex flex-wrap gap-5'>
            {CHANNELS.map(([name, label]) => (
              <Controller
                key={name}
                name={name}
                control={control}
                render={({ field }) => (
                  <label className='flex cursor-pointer items-center gap-2 text-[13px]'>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={(c) => field.onChange(c === true)}
                      disabled={mutation.isPending}
                    />
                    {label}
                  </label>
                )}
              />
            ))}
          </div>
        </Field>

        {/* Adjuntos: en alta se acumulan y se suben tras crear; en edición se gestionan
            de inmediato contra el aviso persistido (subir varios / quitar). */}
        {isEdit && announcement ? (
          <EditAttachmentsManager
            announcementId={announcement.id}
            media={announcement.media ?? []}
            disabled={mutation.isPending}
          />
        ) : (
          <AttachmentsField
            files={files}
            onAdd={addFiles}
            onRemove={(index) => setFiles((prev) => prev.filter((_, i) => i !== index))}
            disabled={mutation.isPending}
          />
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
            {mutation.isPending ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Guardar borrador'}
          </Button>
        </Field>
      </FieldGroup>
    </form>
  )
}
