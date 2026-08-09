import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { AMOUNT_MODE } from '@/modules/chargeConcept/schema'
import {
  PARTICIPATION_TYPE,
  communityProjectFormSchema,
  type CommunityProjectFormValues,
} from '@/modules/communityProject/schema'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { RichTextEditor } from '@/shared/components/richtext/RichTextEditor'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { pesosToMinorUnits } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { Paperclip, X } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

const MAX_DOCUMENTS = 5
const MAX_FILE_BYTES = 10 * 1024 * 1024

interface ProjectModalProps {
  isOpen: boolean
  onClose: () => void
  onCreated: () => void
}

interface ProjectFormProps {
  onClose: () => void
  onCreated: () => void
}

/** Selector de documentos en alta: acumula archivos y se suben tras crear el proyecto. */
function DocumentsField({
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
      <FieldLabel>Documentos</FieldLabel>
      <FieldDescription>
        Hasta {MAX_DOCUMENTS} archivos (PDF, imagen o Word), máx. 10 MB cada uno.
      </FieldDescription>
      <input
        ref={fileInputRef}
        type='file'
        multiple
        accept='image/*,application/pdf,.doc,.docx'
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
          disabled={disabled || files.length >= MAX_DOCUMENTS}
        >
          <Paperclip className='size-4' />
          Agregar documento
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
 * Cuerpo del formulario de proyecto comunitario. Vive en un hijo separado para que
 * `useCatalogs` (POST /selects) solo dispare cuando el Sheet está abierto. La descripción
 * es enriquecida (`description_html`, la sanitiza el servidor); los documentos se acumulan
 * y se suben tras crear el proyecto.
 */
function ProjectForm({ onClose, onCreated }: ProjectFormProps) {
  const defaultValues = useMemo<CommunityProjectFormValues>(
    () => ({
      title: '',
      description_html: '',
      community_project_category_id: '',
      participation_type_id: PARTICIPATION_TYPE.VOLUNTARY,
      goal_amount: null,
      ideal_amount: null,
      min_amount: null,
      allow_decline: true,
      start_date: new Date().toISOString().slice(0, 10),
      due_date: '',
      visible_to_residents: true,
      payments_visible_to_residents: false,
      allow_comments: true,
    }),
    []
  )

  const [files, setFiles] = useState<File[]>([])

  const {
    register,
    handleSubmit,
    control,
    watch,
    formState: { errors },
  } = useForm<CommunityProjectFormValues>({
    resolver: zodResolver(communityProjectFormSchema),
    defaultValues,
  })

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.COMMUNITY_PROJECT_CATEGORY]: { _sort: 'order' },
        [CatalogType.PARTICIPATION_TYPE]: { _sort: 'order' },
      }),
      []
    )
  )

  const participationTypeId = watch('participation_type_id')
  const isMandatory = participationTypeId === PARTICIPATION_TYPE.MANDATORY

  const addFiles = (picked: FileList | null) => {
    if (!picked) return
    const incoming = Array.from(picked)
    const tooBig = incoming.find((file) => file.size > MAX_FILE_BYTES)
    if (tooBig) {
      toast.error(`"${tooBig.name}" supera el límite de 10 MB`)
      return
    }
    setFiles((prev) => {
      const merged = [...prev, ...incoming]
      if (merged.length > MAX_DOCUMENTS) {
        toast.error(`Máximo ${MAX_DOCUMENTS} documentos por proyecto`)
        return merged.slice(0, MAX_DOCUMENTS)
      }
      return merged
    })
  }

  const mutation = useMutation({
    mutationFn: async (values: CommunityProjectFormValues) => {
      const toMinor = (pesos: number | null) =>
        pesos != null && pesos > 0 ? pesosToMinorUnits(pesos) : null
      const created = await CommunityProjectResource.create({
        title: values.title,
        description_html: values.description_html || null,
        community_project_category_id: values.community_project_category_id,
        participation_type_id: values.participation_type_id,
        amount_mode_id: isMandatory ? AMOUNT_MODE.FIXED_REQUIRED : AMOUNT_MODE.RANGE,
        fixed_amount: isMandatory ? toMinor(values.ideal_amount) : null,
        min_amount: !isMandatory ? toMinor(values.min_amount) : null,
        ideal_amount: !isMandatory ? toMinor(values.ideal_amount) : null,
        allow_decline: values.allow_decline,
        target_type: 'all',
        goal_amount: toMinor(values.goal_amount),
        start_date: values.start_date,
        due_date: values.due_date || null,
        visible_to_residents: values.visible_to_residents,
        payments_visible_to_residents: values.payments_visible_to_residents,
        allow_comments: values.allow_comments,
      })
      // El proyecto ya existe; los documentos se suben de uno en uno. Si alguno falla NO
      // rechazamos la mutación (evita que reintente y duplique el borrador): se cuentan.
      let failedUploads = 0
      for (const file of files) {
        try {
          await CommunityProjectResource.uploadMedia(created.id, file)
        } catch {
          failedUploads += 1
        }
      }
      return { failedUploads }
    },
    onSuccess: ({ failedUploads }) => {
      if (failedUploads > 0) {
        toast.error(
          `Proyecto creado, pero ${failedUploads} ${failedUploads === 1 ? 'documento no se subió' : 'documentos no se subieron'} — puedes agregarlos desde el detalle`
        )
      } else {
        toast.success('Proyecto creado como borrador — actívalo cuando esté listo')
      }
      onCreated()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo crear el proyecto'
      )
    },
  })

  return (
    <>
      <form
        className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
      >
        <FieldGroup>
          <RequiredFieldsLegend />
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12'>
              <FieldLabel htmlFor='project-title' required>
                Título
              </FieldLabel>
              <Input
                id='project-title'
                placeholder='Pintura de fachadas 2026'
                {...register('title')}
                disabled={mutation.isPending}
              />
              <FieldError>{errors.title?.message}</FieldError>
            </Field>

            <Field className='col-span-12'>
              <FieldLabel id='project-desc-label' htmlFor='project-desc'>
                Descripción
              </FieldLabel>
              <Controller
                name='description_html'
                control={control}
                render={({ field }) => (
                  <RichTextEditor
                    id='project-desc'
                    ariaLabelledBy='project-desc-label'
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    disabled={mutation.isPending}
                    invalid={!!errors.description_html}
                  />
                )}
              />
              <FieldDescription>
                Da formato con la barra: negrita, listas, enlaces…
              </FieldDescription>
              <FieldError>{errors.description_html?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='project-category' required>
                Categoría
              </FieldLabel>
              <Controller
                name='community_project_category_id'
                control={control}
                render={({ field }) => (
                  <SearchSelect
                    id='project-category'
                    value={field.value}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.COMMUNITY_PROJECT_CATEGORY] ?? []}
                    isDisabled={mutation.isPending}
                    invalid={!!errors.community_project_category_id}
                    placeholder='Seleccionar…'
                  />
                )}
              />
              <FieldError>{errors.community_project_category_id?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel required>Participación</FieldLabel>
              <Controller
                name='participation_type_id'
                control={control}
                render={({ field }) => (
                  <RadioGroup
                    value={field.value}
                    onValueChange={field.onChange}
                    disabled={mutation.isPending}
                    className='flex gap-4 pt-1.5'
                  >
                    <label className='flex cursor-pointer items-center gap-2 text-[13px]'>
                      <RadioGroupItem value={PARTICIPATION_TYPE.VOLUNTARY} />
                      Voluntaria
                    </label>
                    <label className='flex cursor-pointer items-center gap-2 text-[13px]'>
                      <RadioGroupItem value={PARTICIPATION_TYPE.MANDATORY} />
                      Obligatoria (genera cargos)
                    </label>
                  </RadioGroup>
                )}
              />
            </Field>

            <Field className='col-span-6 md:col-span-4'>
              <FieldLabel htmlFor='project-goal'>Meta del proyecto</FieldLabel>
              <div className='relative'>
                <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                  $
                </span>
                <Input
                  id='project-goal'
                  type='number'
                  step='0.01'
                  min={0}
                  className='pl-7'
                  {...register('goal_amount', {
                    setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                  })}
                  disabled={mutation.isPending}
                />
              </div>
            </Field>
            <Field className='col-span-6 md:col-span-4'>
              <FieldLabel htmlFor='project-ideal' required={!isMandatory}>
                {isMandatory ? 'Monto por unidad' : 'Aportación ideal'}
              </FieldLabel>
              <div className='relative'>
                <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                  $
                </span>
                <Input
                  id='project-ideal'
                  type='number'
                  step='0.01'
                  min={0}
                  className='pl-7'
                  {...register('ideal_amount', {
                    setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                  })}
                  disabled={mutation.isPending}
                />
              </div>
              <FieldError>{errors.ideal_amount?.message}</FieldError>
            </Field>
            {!isMandatory && (
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='project-min'>Aportación mínima</FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Input
                    id='project-min'
                    type='number'
                    step='0.01'
                    min={0}
                    className='pl-7'
                    {...register('min_amount', {
                      setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                    })}
                    disabled={mutation.isPending}
                  />
                </div>
              </Field>
            )}

            <Field className='col-span-6 md:col-span-4'>
              <FieldLabel htmlFor='project-start' required>
                Inicio
              </FieldLabel>
              <Input
                id='project-start'
                type='date'
                {...register('start_date')}
                disabled={mutation.isPending}
              />
              <FieldError>{errors.start_date?.message}</FieldError>
            </Field>
            <Field className='col-span-6 md:col-span-4'>
              <FieldLabel htmlFor='project-due'>Fecha límite</FieldLabel>
              <Input
                id='project-due'
                type='date'
                {...register('due_date')}
                disabled={mutation.isPending}
              />
            </Field>
          </div>

          <Field>
            <label className='flex cursor-pointer items-start gap-2 text-[13px]'>
              <Controller
                name='visible_to_residents'
                control={control}
                render={({ field }) => (
                  <Checkbox
                    className='mt-0.5'
                    checked={field.value}
                    onCheckedChange={(c) => field.onChange(c === true)}
                    disabled={mutation.isPending}
                  />
                )}
              />
              <span>
                Proyecto visible para los residentes
                <span className='block text-[12px] text-[var(--ink-3)]'>
                  Encendido: los residentes ven este proyecto. Apagado: es interno, solo la mesa
                  directiva lo ve.
                </span>
              </span>
            </label>
          </Field>

          <Field>
            <label className='flex cursor-pointer items-start gap-2 text-[13px]'>
              <Controller
                name='payments_visible_to_residents'
                control={control}
                render={({ field }) => (
                  <Checkbox
                    className='mt-0.5'
                    checked={field.value}
                    onCheckedChange={(c) => field.onChange(c === true)}
                    disabled={mutation.isPending}
                  />
                )}
              />
              <span>
                Lista de aportaciones visible para los residentes
                <span className='block text-[12px] text-[var(--ink-3)]'>
                  Encendido: los residentes ven quién ha aportado y quién no. Apagado: cada
                  residente solo ve la aportación de su propia unidad.
                </span>
              </span>
            </label>
          </Field>

          <div className='flex flex-wrap gap-5'>
            {(
              [
                ['allow_comments', 'Permitir comentarios'],
                ['allow_decline', 'Permitir declinar'],
              ] as const
            ).map(([name, label]) => (
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

          <DocumentsField
            files={files}
            onAdd={addFiles}
            onRemove={(index) => setFiles((prev) => prev.filter((_, i) => i !== index))}
            disabled={mutation.isPending}
          />

          <FieldDescription>
            El proyecto se crea como borrador; al activarlo se generan las aportaciones
            {isMandatory ? ' y los cargos correspondientes' : ''}.
          </FieldDescription>
        </FieldGroup>
      </form>

      <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
        <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
          Cancelar
        </Button>
        <Button
          type='button'
          onClick={handleSubmit((values) => mutation.mutate(values))}
          disabled={mutation.isPending}
          className='min-w-[140px]'
        >
          {mutation.isPending ? 'Creando…' : 'Crear proyecto'}
        </Button>
      </SheetFooter>
    </>
  )
}

/** Nuevo proyecto comunitario (§11.4) — siempre nace como borrador. */
export function ProjectFormModal({ isOpen, onClose, onCreated }: ProjectModalProps) {
  if (!isOpen) return null

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-2xl'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>Nuevo proyecto comunitario</SheetTitle>
        </SheetHeader>

        <ProjectForm onClose={onClose} onCreated={onCreated} />
      </SheetContent>
    </Sheet>
  )
}
