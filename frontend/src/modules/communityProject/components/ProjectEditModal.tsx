import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import {
  communityProjectEditSchema,
  type CommunityProjectEditValues,
  type ICommunityProject,
} from '@/modules/communityProject/schema'
import { CommunityProjectResource } from '@/modules/communityProject/services/CommunityProjectResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { RichTextEditor } from '@/shared/components/richtext/RichTextEditor'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface ProjectEditModalProps {
  isOpen: boolean
  onClose: () => void
  project: ICommunityProject
}

interface ProjectEditFormProps {
  onClose: () => void
  project: ICommunityProject
}

/**
 * Formulario de edición restringida: solo la presentación del proyecto es editable
 * (título, descripción enriquecida y las dos casillas de visibilidad). El resto de
 * los datos quedan congelados tras crearlo (el backend rechaza cualquier otro campo).
 */
function ProjectEditForm({ onClose, project }: ProjectEditFormProps) {
  const queryClient = useQueryClient()

  const defaultValues = useMemo<CommunityProjectEditValues>(
    () => ({
      title: project.title,
      description_html: project.description_html ?? '',
      visible_to_residents: project.visible_to_residents ?? true,
      payments_visible_to_residents: project.payments_visible_to_residents ?? false,
    }),
    [project]
  )

  const {
    register,
    handleSubmit,
    control,
    formState: { errors },
  } = useForm<CommunityProjectEditValues>({
    resolver: zodResolver(communityProjectEditSchema),
    defaultValues,
  })

  const mutation = useMutation({
    mutationFn: (values: CommunityProjectEditValues) =>
      CommunityProjectResource.update(project.id, {
        title: values.title,
        description_html: values.description_html ?? '',
        visible_to_residents: values.visible_to_residents,
        payments_visible_to_residents: values.payments_visible_to_residents,
      }),
    onSuccess: () => {
      toast.success('Proyecto actualizado')
      void queryClient.invalidateQueries({ queryKey: ['project'] })
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo actualizar el proyecto'
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

          <Field>
            <FieldLabel htmlFor='edit-project-title' required>
              Título
            </FieldLabel>
            <Input
              id='edit-project-title'
              placeholder='Pintura de fachadas 2026'
              {...register('title')}
              disabled={mutation.isPending}
            />
            <FieldError>{errors.title?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel id='edit-project-desc-label' htmlFor='edit-project-desc'>
              Descripción
            </FieldLabel>
            <Controller
              name='description_html'
              control={control}
              render={({ field }) => (
                <RichTextEditor
                  id='edit-project-desc'
                  ariaLabelledBy='edit-project-desc-label'
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  disabled={mutation.isPending}
                  invalid={!!errors.description_html}
                />
              )}
            />
            <FieldDescription>Da formato con la barra: negrita, listas, enlaces…</FieldDescription>
            <FieldError>{errors.description_html?.message}</FieldError>
          </Field>

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

          <FieldDescription>
            Los demás datos del proyecto no se pueden modificar después de crearlo.
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
          {mutation.isPending ? 'Guardando…' : 'Guardar cambios'}
        </Button>
      </SheetFooter>
    </>
  )
}

/** Edición restringida de un proyecto comunitario (solo presentación). */
export function ProjectEditModal({ isOpen, onClose, project }: ProjectEditModalProps) {
  if (!isOpen) return null

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-2xl'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>Editar proyecto</SheetTitle>
        </SheetHeader>

        <ProjectEditForm onClose={onClose} project={project} />
      </SheetContent>
    </Sheet>
  )
}
