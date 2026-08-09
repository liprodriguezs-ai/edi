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
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import {
  ticketFormSchema,
  type ITicket,
  type TicketFormValues,
} from '@/modules/ticket/schema'
import { TicketResource } from '@/modules/ticket/services/TicketResource'
import { useTicketImportanceStore } from '@/modules/ticket/store/ticketImportanceStore'
import { useTicketStore } from '@/modules/ticket/store/ticketStore'
import { useTicketStatusStore } from '@/modules/ticket/store/ticketStatusStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useRef } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface TicketFormProps extends IFormProps<ITicket> {
  onSubmitRef?: React.MutableRefObject<(() => void) | null>
}

export const TicketForm = ({ mode, entity, onSuccess, onCancel, formId, onSubmitRef }: TicketFormProps) => {
  const statuses = useTicketStatusStore((s) => s.entities)
  const importances = useTicketImportanceStore((s) => s.entities)

  const defaultValues = useMemo<TicketFormValues>(() => ({
    title: entity?.title ?? '',
    description: entity?.description ?? '',
    ticket_importance_id: entity?.ticket_importance_id ?? '',
    ticket_status_id: entity?.ticket_status_id ?? '',
  }), [entity])

  const crud = useBaseCrud<ITicket, TicketFormValues>({
    store: useTicketStore,
    resource: {
      save: TicketResource.create,
      update: TicketResource.update,
      delete: TicketResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<TicketFormValues>({
    resolver: zodResolver(ticketFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      onSuccess?.(result)
    }
  })

  // Expone el trigger de submit al modal padre via ref
  const doSubmitRef = useRef(doSubmit)
  doSubmitRef.current = doSubmit

  useEffect(() => {
    if (onSubmitRef) {
      onSubmitRef.current = () => void doSubmitRef.current()
    }
  }, [onSubmitRef])

  const isSaving = crud.store.isSaving

  return (
    <form id={formId} onSubmit={(e) => { e.preventDefault(); void doSubmit(e) }}>
      <FieldGroup>
        <FieldSet>
          <FieldLegend>Informacion del ticket</FieldLegend>
          <FieldDescription>Datos principales del ticket de soporte</FieldDescription>
          <FieldGroup>
            <div className="grid grid-cols-12 gap-4">
              {/* Asunto */}
              <Field className="col-span-12">
                <FieldLabel htmlFor="ticket-title" required>
                  Asunto
                </FieldLabel>
                <Input
                  id="ticket-title"
                  placeholder="Asunto del ticket"
                  {...register('title')}
                  disabled={isSaving}
                />
                <FieldError>{errors.title?.message}</FieldError>
              </Field>

              {/* Importancia */}
              {mode === CrudMode.EDIT && (
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="ticket-importance">Prioridad</FieldLabel>
                <Controller
                  name="ticket_importance_id"
                  control={control}
                  render={({ field }) => (
                    <Select
                      value={field.value || undefined}
                      onValueChange={field.onChange}
                      disabled={isSaving}
                    >
                      <SelectTrigger id="ticket-importance" className="w-full">
                        <SelectValue placeholder="Seleccionar prioridad..." />
                      </SelectTrigger>
                      <SelectContent>
                        {importances.map((option) => (
                          <SelectItem key={option.id} value={option.id}>
                            {option.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{errors.ticket_importance_id?.message}</FieldError>
              </Field>
              )}

              {/* Descripcion */}
              <Field className="col-span-12">
                <FieldLabel htmlFor="ticket-description" required>
                  Descripcion
                </FieldLabel>
                <Textarea
                  id="ticket-description"
                  placeholder="Describe el problema o solicitud con detalle..."
                  className="resize-none"
                  rows={6}
                  {...register('description')}
                  disabled={isSaving}
                />
                <FieldDescription>Minimo 20 caracteres</FieldDescription>
                <FieldError>{errors.description?.message}</FieldError>
              </Field>

              {/* Estado (solo en edicion) */}
              {mode === CrudMode.EDIT && (
                <Field className="col-span-12 md:col-span-6">
                  <FieldLabel htmlFor="ticket-status">Estatus</FieldLabel>
                  <Controller
                    name="ticket_status_id"
                    control={control}
                    render={({ field }) => (
                      <Select
                        value={field.value || undefined}
                        onValueChange={field.onChange}
                        disabled={isSaving}
                      >
                        <SelectTrigger id="ticket-status" className="w-full">
                          <SelectValue placeholder="Seleccionar estatus..." />
                        </SelectTrigger>
                        <SelectContent>
                          {statuses.map((status) => (
                            <SelectItem key={status.id} value={status.id}>
                              <span className="flex items-center gap-2">
                                <span
                                  className="inline-block size-2 rounded-full shrink-0"
                                  style={{ backgroundColor: status.color }}
                                />
                                {status.name}
                              </span>
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  />
                  <FieldError>{errors.ticket_status_id?.message}</FieldError>
                </Field>
              )}
            </div>
          </FieldGroup>
        </FieldSet>

        {!formId && !onSubmitRef && (
          <Field orientation="horizontal" className="justify-end border-t border-gray-200 pt-6">
            {onCancel && (
              <Button
                type="button"
                variant="outline"
                onClick={onCancel}
                disabled={isSaving}
                className="min-w-[80px]"
              >
                Cancelar
              </Button>
            )}
            <Button type="submit" disabled={isSaving} className="min-w-[100px]">
              {isSaving
                ? mode === CrudMode.EDIT
                  ? 'Actualizando...'
                  : 'Creando...'
                : mode === CrudMode.EDIT
                  ? 'Actualizar'
                  : 'Crear'}
            </Button>
          </Field>
        )}
      </FieldGroup>
    </form>
  )
}
