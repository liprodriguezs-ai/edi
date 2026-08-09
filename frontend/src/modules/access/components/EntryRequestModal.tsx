import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { entryRequestSchema, type EntryRequestValues } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { UnitCombobox } from '@/modules/unit/components/UnitCombobox'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface EntryRequestModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * Solicitud de autorización del guardia (§14.4.4): registra una visita sin QR
 * previo y notifica a los residentes de la unidad para que aprueben o rechacen.
 */
export function EntryRequestModal({ isOpen, onClose }: EntryRequestModalProps) {
  const queryClient = useQueryClient()

  const defaultValues = useMemo<EntryRequestValues>(() => ({
    unit_id: '',
    name: '',
    visitor_motive_id: '',
    plate: '',
    notes: '',
    id_document_type: undefined,
    id_document_number: '',
  }), [])

  const {
    register, handleSubmit, reset, control, setValue, watch,
    formState: { errors },
  } = useForm<EntryRequestValues>({
    resolver: zodResolver(entryRequestSchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) reset(defaultValues)
  }, [isOpen, reset, defaultValues])

  const { catalogs } = useCatalogs(useMemo(() => ({
    [CatalogType.VISITOR_MOTIVE]: { _sort: 'order' },
  }), []))

  const mutation = useMutation({
    mutationFn: (values: EntryRequestValues) =>
      AccessLogResource.entryRequest({
        ...values,
        plate: values.plate || undefined,
        notes: values.notes || undefined,
        id_document_number: values.id_document_number || undefined,
        id_document_type: values.id_document_type || undefined,
      }),
    onSuccess: () => {
      toast.success('Solicitud enviada — esperando aprobación del residente')
      void queryClient.invalidateQueries({ queryKey: ['access'] })
      onClose()
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo enviar la solicitud')
    },
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg p-0" aria-describedby={undefined}>
        <DialogHeader className="px-6 pt-6">
          <DialogTitle>Solicitar autorización de entrada</DialogTitle>
        </DialogHeader>

        <form className="px-6 py-2" onSubmit={handleSubmit((values) => mutation.mutate(values))}>
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12">
                <FieldLabel htmlFor="req-name" required>Nombre del visitante</FieldLabel>
                <Input id="req-name" {...register('name')} disabled={mutation.isPending} />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>

              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="req-unit" required>Unidad que visita</FieldLabel>
                <UnitCombobox
                  id="req-unit"
                  value={watch('unit_id')}
                  disabled={mutation.isPending}
                  onSelect={(unit) => setValue('unit_id', unit.id, { shouldValidate: true })}
                />
                <FieldError>{errors.unit_id?.message}</FieldError>
              </Field>

              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="req-motive" required>Motivo</FieldLabel>
                <Controller
                  name="visitor_motive_id"
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id="req-motive"
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.VISITOR_MOTIVE] ?? []}
                      isDisabled={mutation.isPending}
                      invalid={!!errors.visitor_motive_id}
                      placeholder="Seleccionar…"
                    />
                  )}
                />
                <FieldError>{errors.visitor_motive_id?.message}</FieldError>
              </Field>

              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="req-plate">Placa</FieldLabel>
                <Input id="req-plate" placeholder="ABC-123-4" {...register('plate')} disabled={mutation.isPending} />
              </Field>

              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="req-doc-type">Tipo de identificación</FieldLabel>
                <Controller
                  name="id_document_type"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value || undefined} onValueChange={field.onChange} disabled={mutation.isPending}>
                      <SelectTrigger id="req-doc-type" className="w-full">
                        <SelectValue placeholder="Tipo…" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="ine">INE</SelectItem>
                        <SelectItem value="passport">Pasaporte</SelectItem>
                        <SelectItem value="driver_license">Licencia</SelectItem>
                        <SelectItem value="other">Otra</SelectItem>
                      </SelectContent>
                    </Select>
                  )}
                />
              </Field>

              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="req-doc-num">Núm. de documento</FieldLabel>
                <Input id="req-doc-num" {...register('id_document_number')} disabled={mutation.isPending} />
              </Field>

              <Field className="col-span-12">
                <FieldLabel htmlFor="req-notes">Notas</FieldLabel>
                <Textarea id="req-notes" rows={2} className="resize-none" {...register('notes')} disabled={mutation.isPending} />
                <FieldDescription>Se enviará una notificación urgente al residente para aprobar la entrada.</FieldDescription>
              </Field>
            </div>
          </FieldGroup>
        </form>

        <DialogFooter className="px-6 pb-6">
          <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>Cancelar</Button>
          <Button
            type="button"
            onClick={handleSubmit((values) => mutation.mutate(values))}
            disabled={mutation.isPending}
            className="min-w-[160px]"
          >
            {mutation.isPending ? 'Enviando…' : 'Enviar solicitud'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
