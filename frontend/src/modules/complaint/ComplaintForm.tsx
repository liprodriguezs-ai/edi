import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import type { IComplaint, ComplaintFormValues } from '@/modules/complaint/schema'
import { complaintFormSchema } from '@/modules/complaint/schema'
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

const EMPTY_VALUES: ComplaintFormValues = {
  title: '',
  complaint_category_id: '',
  complaint_priority_id: '',
  against_unit_id: undefined,
  against_description: '',
}

interface ComplaintFormProps {
  isOpen: boolean
  onClose: () => void
  onCreated: (_complaint?: IComplaint) => void
}

export function ComplaintForm({ isOpen, onClose, onCreated }: ComplaintFormProps) {
  const evidenceRef = useRef<HTMLInputElement>(null)
  const [evidenceFile, setEvidenceFile] = useState<File | null>(null)

  const {
    catalogs: categoryCatalogs,
    enable: enableCategories,
    isLoading: loadingCategories,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.COMPLAINT_CATEGORY]: { _sort: 'order' } }), []))
  const {
    catalogs: priorityCatalogs,
    enable: enablePriorities,
    isLoading: loadingPriorities,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.COMPLAINT_PRIORITY]: { _sort: 'order' } }), []))
  const {
    catalogs: unitCatalogs,
    enable: enableUnits,
    isLoading: loadingUnits,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.UNIT]: { _sort: 'number' } }), []))

  const categoryOptions = categoryCatalogs[CatalogType.COMPLAINT_CATEGORY] ?? []
  const priorityOptions = priorityCatalogs[CatalogType.COMPLAINT_PRIORITY] ?? []
  const unitOptions = useMemo(
    () =>
      (unitCatalogs[CatalogType.UNIT] ?? []).map((u) => ({
        id: u.id,
        name: (u.full_identifier as string | undefined) ?? (u.number as string | undefined) ?? u.id,
      })),
    [unitCatalogs]
  )

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors },
  } = useForm<ComplaintFormValues>({
    resolver: zodResolver(complaintFormSchema),
    defaultValues: EMPTY_VALUES,
  })

  const resetForm = () => {
    reset(EMPTY_VALUES)
    setEvidenceFile(null)
    // Native file inputs are uncontrolled — clearing the state alone leaves the
    // previously picked file name shown; must clear the DOM value too.
    if (evidenceRef.current) evidenceRef.current.value = ''
  }

  const mutation = useMutation({
    mutationFn: async (values: ComplaintFormValues) => {
      if (evidenceFile) {
        const form = new FormData()
        const entries = Object.entries(values) as [string, unknown][]
        for (const [key, value] of entries) {
          if (value !== undefined && value !== null && value !== '') {
            form.append(key, String(value))
          }
        }
        form.append('evidence', evidenceFile)
        return ComplaintResource.create(form)
      }
      return ComplaintResource.create(values)
    },
    onSuccess: (complaint) => {
      toast.success(`Incidencia ${complaint.case_number} registrada`)
      resetForm()
      onCreated(complaint)
    },
    onError: (error) => {
      const { title } = formatApiErrorMessage(
        error instanceof ApiError ? error : new ApiError('Error al guardar', 500)
      )
      toast.error(title)
    },
  })

  const submit = handleSubmit((values) => mutation.mutate(values))

  const handleClose = () => {
    resetForm()
    onClose()
  }

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        if (!open) handleClose()
      }}
    >
      <DialogContent className='max-h-[90dvh] overflow-y-auto sm:max-w-[560px]'>
        <DialogHeader>
          <DialogTitle>Registrar incidencia</DialogTitle>
        </DialogHeader>

        <form onSubmit={submit} className='space-y-4'>
          <RequiredFieldsLegend />

          {/* Title */}
          <Field>
            <FieldLabel htmlFor='cf-title' required>
              Título
            </FieldLabel>
            <Input
              id='cf-title'
              placeholder='Resumen breve de la incidencia'
              className={errors.title ? 'border-destructive' : ''}
              {...register('title')}
            />
            {errors.title && <p className='text-[12px] text-destructive'>{errors.title.message}</p>}
          </Field>

          {/* Category */}
          <Field>
            <FieldLabel htmlFor='cf-category' required>
              Categoría
            </FieldLabel>
            <Controller
              name='complaint_category_id'
              control={control}
              render={({ field }) => (
                <Select
                  value={field.value}
                  onValueChange={field.onChange}
                  onOpenChange={(open) => {
                    if (open) enableCategories()
                  }}
                >
                  <SelectTrigger
                    id='cf-category'
                    className={errors.complaint_category_id ? 'border-destructive' : ''}
                  >
                    <SelectValue
                      placeholder={loadingCategories ? 'Cargando…' : 'Seleccionar categoría'}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {categoryOptions.map((c) => (
                      <SelectItem key={c.id} value={c.id}>
                        {c.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            {errors.complaint_category_id && (
              <p className='text-[12px] text-destructive'>{errors.complaint_category_id.message}</p>
            )}
          </Field>

          {/* Priority */}
          <Field>
            <FieldLabel htmlFor='cf-priority' required>
              Prioridad
            </FieldLabel>
            <Controller
              name='complaint_priority_id'
              control={control}
              render={({ field }) => (
                <Select
                  value={field.value}
                  onValueChange={field.onChange}
                  onOpenChange={(open) => {
                    if (open) enablePriorities()
                  }}
                >
                  <SelectTrigger
                    id='cf-priority'
                    className={errors.complaint_priority_id ? 'border-destructive' : ''}
                  >
                    <SelectValue
                      placeholder={loadingPriorities ? 'Cargando…' : 'Seleccionar prioridad'}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {priorityOptions.map((p) => (
                      <SelectItem key={p.id} value={p.id}>
                        {p.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            {errors.complaint_priority_id && (
              <p className='text-[12px] text-destructive'>{errors.complaint_priority_id.message}</p>
            )}
          </Field>

          {/* Denounced unit (optional) */}
          <Field>
            <FieldLabel htmlFor='cf-unit'>Unidad señalada (opcional)</FieldLabel>
            <Controller
              name='against_unit_id'
              control={control}
              render={({ field }) => (
                <SearchSelect
                  id='cf-unit'
                  value={field.value || null}
                  onChange={(v) => field.onChange((v as string | null) ?? undefined)}
                  options={unitOptions}
                  placeholder='Sin unidad específica'
                  isLoading={loadingUnits}
                  isClearable
                  onMenuOpen={enableUnits}
                />
              )}
            />
            <p className='text-[12px] text-muted-foreground'>
              Déjalo vacío si la incidencia no está vinculada a una unidad (ej. contra la
              administración o una falla general del condominio).
            </p>
          </Field>

          {/* Description */}
          <Field>
            <FieldLabel htmlFor='cf-description' required>
              Descripción
            </FieldLabel>
            <Textarea
              id='cf-description'
              placeholder='Describe los hechos reportados con el mayor detalle posible…'
              rows={4}
              className={errors.against_description ? 'border-destructive' : ''}
              {...register('against_description')}
            />
            {errors.against_description && (
              <p className='text-[12px] text-destructive'>{errors.against_description.message}</p>
            )}
          </Field>

          {/* Evidence */}
          <Field>
            <FieldLabel htmlFor='cf-evidence'>Evidencia</FieldLabel>
            <input
              ref={evidenceRef}
              id='cf-evidence'
              type='file'
              accept='image/jpeg,image/png,image/webp,application/pdf'
              className='block w-full text-sm text-muted-foreground file:mr-3 file:rounded-full file:border-0 file:bg-[var(--surface-2)] file:px-3 file:py-1.5 file:text-xs file:font-medium'
              onChange={(e) => setEvidenceFile(e.target.files?.[0] ?? null)}
            />
            {evidenceFile && (
              <p className='text-[11.5px] text-muted-foreground'>{evidenceFile.name}</p>
            )}
          </Field>

          <DialogFooter>
            <Button
              type='button'
              variant='outline'
              onClick={handleClose}
              disabled={mutation.isPending}
            >
              Cancelar
            </Button>
            <Button type='submit' disabled={mutation.isPending}>
              {mutation.isPending ? 'Registrando…' : 'Registrar incidencia'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
