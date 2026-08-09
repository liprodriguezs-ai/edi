import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { startPeriodSchema, type StartPeriodValues } from '@/modules/boardPeriod/schema'
import { BoardPeriodResource } from '@/modules/boardPeriod/services/BoardPeriodResource'
import { UserCombobox } from '@/modules/unit/components/UserCombobox'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useMemo } from 'react'
import { Controller, useFieldArray, useForm } from 'react-hook-form'

interface StartPeriodModalProps {
  isOpen: boolean
  onClose: () => void
  onCreated: () => void
}

interface StartPeriodFormProps {
  onClose: () => void
  onCreated: () => void
}

/**
 * Iniciar nuevo periodo (§7.3.2): nombre + vigencia + miembros con cargo.
 * Al crear, el backend asigna los roles Spatie y cierra el periodo anterior
 * si así se indica.
 *
 * El cuerpo del formulario (con `useCatalogs` y demás hooks) vive en el hijo
 * `StartPeriodForm`, que sólo se monta cuando el sheet está abierto. Así
 * entrar a la lista NO dispara el `/selects` del catálogo de cargos: éste se
 * carga únicamente al abrir el modal.
 */
export function StartPeriodModal({ isOpen, onClose, onCreated }: StartPeriodModalProps) {
  if (!isOpen) {
    return null
  }

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-2xl'
        aria-describedby={undefined}
      >
        <StartPeriodForm onClose={onClose} onCreated={onCreated} />
      </SheetContent>
    </Sheet>
  )
}

function StartPeriodForm({ onClose, onCreated }: StartPeriodFormProps) {
  const currentYear = new Date().getFullYear()

  const defaultValues = useMemo<StartPeriodValues>(
    () => ({
      name: `Mesa Directiva ${currentYear}–${currentYear + 1}`,
      start_date: new Date().toISOString().slice(0, 10),
      end_date: '',
      notes: '',
      auto_close_current: true,
      members: [],
    }),
    [currentYear]
  )

  const {
    register,
    handleSubmit,
    control,
    setValue,
    watch,
    formState: { errors },
  } = useForm<StartPeriodValues>({
    resolver: zodResolver(startPeriodSchema),
    defaultValues,
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'members' })

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.BOARD_ROLE]: { _sort: 'order' },
      }),
      []
    )
  )

  const mutation = useMutation({
    mutationFn: (values: StartPeriodValues) =>
      BoardPeriodResource.startPeriod({
        name: values.name,
        start_date: values.start_date,
        end_date: values.end_date,
        notes: values.notes || undefined,
        auto_close_current: values.auto_close_current,
        members: values.members.map((member) => ({
          user_id: member.user_id,
          board_role_id: member.board_role_id,
        })),
      }),
    onSuccess: () => {
      toast.success('Periodo iniciado — los roles de mesa fueron asignados')
      onCreated()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo iniciar el periodo'
      )
    },
  })

  return (
    <>
      <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
        <SheetTitle>Iniciar nuevo periodo</SheetTitle>
      </SheetHeader>

      <form
        className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
      >
        <FieldGroup>
          <RequiredFieldsLegend />
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12'>
              <FieldLabel htmlFor='period-name' required>
                Nombre del periodo
              </FieldLabel>
              <Input id='period-name' {...register('name')} disabled={mutation.isPending} />
              <FieldError>{errors.name?.message}</FieldError>
            </Field>
            <Field className='col-span-6'>
              <FieldLabel htmlFor='period-start' required>
                Inicio
              </FieldLabel>
              <Input
                id='period-start'
                type='date'
                {...register('start_date')}
                disabled={mutation.isPending}
              />
              <FieldError>{errors.start_date?.message}</FieldError>
            </Field>
            <Field className='col-span-6'>
              <FieldLabel htmlFor='period-end' required>
                Fin
              </FieldLabel>
              <Input
                id='period-end'
                type='date'
                {...register('end_date')}
                disabled={mutation.isPending}
              />
              <FieldError>{errors.end_date?.message}</FieldError>
            </Field>
          </div>

          <Field>
            <div className='flex items-center justify-between'>
              <FieldLabel required>Miembros de la mesa ({fields.length})</FieldLabel>
              <Button
                type='button'
                variant='outline'
                size='sm'
                className='h-8 rounded-full text-[12.5px]'
                onClick={() => append({ user_id: '', board_role_id: '', user_name: '' })}
                disabled={mutation.isPending}
              >
                <Plus className='size-3.5' />
                Agregar cargo
              </Button>
            </div>
            {errors.members?.message && <FieldError>{errors.members.message}</FieldError>}
            {errors.members?.root?.message && (
              <FieldError>{errors.members.root.message}</FieldError>
            )}

            <div className='space-y-3'>
              {fields.map((field, index) => (
                <div
                  key={field.id}
                  className='rounded-[12px] border border-border bg-[var(--surface-2)] p-3'
                >
                  <div className='grid grid-cols-12 items-start gap-3'>
                    <div className='col-span-12 md:col-span-6'>
                      <UserCombobox
                        value={watch(`members.${index}.user_id`)}
                        initialLabel={watch(`members.${index}.user_name`) || undefined}
                        disabled={mutation.isPending}
                        invalid={!!errors.members?.[index]?.user_id}
                        placeholder='Buscar persona…'
                        onSelect={(user) => {
                          setValue(`members.${index}.user_id`, user.id, { shouldValidate: true })
                          setValue(`members.${index}.user_name`, user.full_name ?? user.name)
                        }}
                        onClear={() => {
                          setValue(`members.${index}.user_id`, '')
                          setValue(`members.${index}.user_name`, '')
                        }}
                      />
                      <FieldError>{errors.members?.[index]?.user_id?.message}</FieldError>
                    </div>
                    <div className='col-span-10 md:col-span-5'>
                      <Controller
                        name={`members.${index}.board_role_id`}
                        control={control}
                        render={({ field: rhf }) => (
                          <SearchSelect
                            value={rhf.value}
                            onChange={(v) => rhf.onChange((v as string | null) ?? '')}
                            options={catalogs[CatalogType.BOARD_ROLE] ?? []}
                            isDisabled={mutation.isPending}
                            invalid={!!errors.members?.[index]?.board_role_id}
                            placeholder='Cargo…'
                          />
                        )}
                      />
                      <FieldError>{errors.members?.[index]?.board_role_id?.message}</FieldError>
                    </div>
                    <div className='col-span-2 md:col-span-1 flex justify-end'>
                      <Button
                        type='button'
                        variant='ghost'
                        size='icon'
                        className='size-9 text-destructive hover:text-destructive'
                        onClick={() => remove(index)}
                        aria-label='Quitar miembro'
                        disabled={mutation.isPending}
                      >
                        <Trash2 className='size-4' />
                      </Button>
                    </div>
                  </div>
                </div>
              ))}
              {fields.length === 0 && (
                <p className='rounded-[10px] border border-dashed border-border px-4 py-5 text-center text-[13px] text-[var(--ink-3)]'>
                  Agrega los cargos electos: presidente, tesorero, secretario, vocales…
                </p>
              )}
            </div>
          </Field>

          <Controller
            name='auto_close_current'
            control={control}
            render={({ field }) => (
              <label className='flex cursor-pointer items-center gap-2 text-[13px]'>
                <Checkbox
                  checked={field.value}
                  onCheckedChange={(c) => field.onChange(c === true)}
                  disabled={mutation.isPending}
                />
                Cerrar automáticamente el periodo vigente (revoca sus roles)
              </label>
            )}
          />

          <Field>
            <FieldLabel htmlFor='period-notes'>Notas</FieldLabel>
            <Textarea
              id='period-notes'
              rows={2}
              className='resize-none'
              {...register('notes')}
              disabled={mutation.isPending}
            />
            <FieldDescription>Acuerdos de asamblea, observaciones…</FieldDescription>
          </Field>
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
          {mutation.isPending ? 'Iniciando…' : 'Iniciar periodo'}
        </Button>
      </SheetFooter>
    </>
  )
}
