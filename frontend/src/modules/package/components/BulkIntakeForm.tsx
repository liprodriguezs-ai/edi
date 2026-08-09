import { Button } from '@/components/ui/button'
import { DialogFooter } from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { PackageBatchValues } from '@/modules/package/schema'
import { packageBatchSchema } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { usePackageCatalogOptions } from '@/modules/package/hooks/usePackageCatalogOptions'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { Minus, Plus } from 'lucide-react'
import { useMemo } from 'react'
import { Controller, useFieldArray, useForm, useWatch } from 'react-hook-form'

const EMPTY_BATCH_ITEM = {
  unit_id: '',
  package_carrier_id: undefined,
  package_type_id: undefined,
  recipient_name: undefined,
  tracking_number: undefined,
  description: undefined,
  storage_location: undefined,
  carrier_other: undefined,
}

interface BulkIntakeFormProps {
  onCreated: () => void
  onClose: () => void
}

/**
 * Form for registering up to 50 packages at once via the intake-batch endpoint.
 * Per-row photo upload is not supported in batch mode (only the single-intake form has it).
 */
export function BulkIntakeForm({ onCreated, onClose }: BulkIntakeFormProps) {
  const {
    unitOptions,
    carrierOptions,
    typeOptions,
    enableUnits,
    enableCarriers,
    enableTypes,
    loadingUnits,
    restrictedUnitIds,
  } = usePackageCatalogOptions()

  const {
    control,
    handleSubmit,
    register,
    formState: { errors },
  } = useForm<PackageBatchValues>({
    resolver: zodResolver(packageBatchSchema),
    defaultValues: { packages: [{ ...EMPTY_BATCH_ITEM }] },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'packages' })

  // Delinquency guard (design §8.3): per-row note + submit blocked while ANY
  // row holds a restricted unit — units stay selectable in every row's Select.
  const watchedPackages = useWatch({ control, name: 'packages' })
  const restrictedRowIndices = useMemo(() => {
    const indices = new Set<number>()
    watchedPackages?.forEach((row, index) => {
      if (row?.unit_id && restrictedUnitIds.has(row.unit_id)) indices.add(index)
    })
    return indices
  }, [watchedPackages, restrictedUnitIds])
  const hasRestrictedRow = restrictedRowIndices.size > 0

  const mutation = useMutation({
    mutationFn: (values: PackageBatchValues) => {
      // Batch does NOT support per-row photos (only single intake supports photo upload).
      const cleaned = values.packages.map((row) => {
        const out: Record<string, unknown> = {}
        for (const [k, v] of Object.entries(row)) {
          if (v !== undefined && v !== null && v !== '') out[k] = v
        }
        return out
      })
      return PackageResource.batch({ packages: cleaned })
    },
    onSuccess: (result) => {
      toast.success(`${result.length} paquetes registrados`)
      onCreated()
    },
    onError: (error) => {
      const { title } = formatApiErrorMessage(
        error instanceof ApiError ? error : new ApiError('Error', 500)
      )
      toast.error(title)
    },
  })

  const submitBatch = handleSubmit((values) => {
    // Delinquency guard (design §8.3): units stay selectable in every row —
    // only the submit itself is stopped while any row holds a restricted
    // unit (defense in depth vs. Enter-key submit; backend 403 is authority).
    if (values.packages.some((row) => restrictedUnitIds.has(row.unit_id))) return
    mutation.mutate(values)
  })

  return (
    <form onSubmit={submitBatch} className='space-y-4'>
      <RequiredFieldsLegend />
      {errors.packages?.root && (
        <p className='text-[12px] text-destructive'>{errors.packages.root.message}</p>
      )}
      <p className='text-[12.5px] text-muted-foreground'>
        Registra hasta 50 paquetes en un solo lote. La foto no está disponible en el registro
        masivo.
      </p>

      <div className='space-y-4'>
        {fields.map((field, index) => (
          <div
            key={field.id}
            className='relative rounded-[12px] border border-border bg-[var(--surface-2)] p-4'
          >
            <div className='mb-3 flex items-center justify-between'>
              <span className='text-[12px] font-semibold text-muted-foreground'>
                Paquete #{index + 1}
              </span>
              {fields.length > 1 && (
                <button
                  type='button'
                  onClick={() => remove(index)}
                  className='text-destructive hover:text-destructive/80'
                  aria-label={`Eliminar paquete ${index + 1}`}
                >
                  <Minus className='size-4' />
                </button>
              )}
            </div>

            <div className='grid grid-cols-2 gap-3'>
              {/* Unit */}
              <Field className='col-span-2'>
                <FieldLabel required>Unidad</FieldLabel>
                <Controller
                  name={`packages.${index}.unit_id`}
                  control={control}
                  render={({ field: f }) => (
                    <Select
                      value={f.value}
                      onValueChange={f.onChange}
                      onOpenChange={(open) => {
                        if (open) enableUnits()
                      }}
                    >
                      <SelectTrigger
                        className={errors.packages?.[index]?.unit_id ? 'border-destructive' : ''}
                      >
                        <SelectValue
                          placeholder={loadingUnits ? 'Cargando…' : 'Seleccionar unidad'}
                        />
                      </SelectTrigger>
                      <SelectContent>
                        {unitOptions.map((u) => (
                          <SelectItem key={u.value} value={u.value}>
                            {u.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                {restrictedRowIndices.has(index) && (
                  <p className='text-[12px] font-medium text-amber-600'>
                    Esta unidad no puede recibir paquetes.
                  </p>
                )}
              </Field>

              {/* Carrier */}
              <Field>
                <FieldLabel>Transportista</FieldLabel>
                <Controller
                  name={`packages.${index}.package_carrier_id`}
                  control={control}
                  render={({ field: f }) => (
                    <Select
                      value={f.value || undefined}
                      onValueChange={(v) => f.onChange(v || undefined)}
                      onOpenChange={(open) => {
                        if (open) enableCarriers()
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder='Sin especificar' />
                      </SelectTrigger>
                      <SelectContent>
                        {carrierOptions.map((c) => (
                          <SelectItem key={c.value} value={c.value}>
                            {c.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </Field>

              {/* Type */}
              <Field>
                <FieldLabel>Tipo</FieldLabel>
                <Controller
                  name={`packages.${index}.package_type_id`}
                  control={control}
                  render={({ field: f }) => (
                    <Select
                      value={f.value || undefined}
                      onValueChange={(v) => f.onChange(v || undefined)}
                      onOpenChange={(open) => {
                        if (open) enableTypes()
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder='Sin especificar' />
                      </SelectTrigger>
                      <SelectContent>
                        {typeOptions.map((t) => (
                          <SelectItem key={t.value} value={t.value}>
                            {t.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </Field>

              {/* Recipient name */}
              <Field className='col-span-2'>
                <FieldLabel>Destinatario</FieldLabel>
                <Input
                  placeholder='Nombre en el paquete'
                  {...register(`packages.${index}.recipient_name`)}
                />
              </Field>

              {/* Tracking */}
              <Field>
                <FieldLabel>Rastreo</FieldLabel>
                <Input
                  placeholder='Número de rastreo'
                  {...register(`packages.${index}.tracking_number`)}
                />
              </Field>

              {/* Storage location */}
              <Field>
                <FieldLabel>Almacén</FieldLabel>
                <Input
                  placeholder='Estante A-3'
                  {...register(`packages.${index}.storage_location`)}
                />
              </Field>
            </div>
          </div>
        ))}
      </div>

      <Button
        type='button'
        variant='outline'
        size='sm'
        className='w-full'
        onClick={() => append({ ...EMPTY_BATCH_ITEM })}
        disabled={fields.length >= 50}
      >
        <Plus className='mr-1.5 size-4' />
        Agregar paquete
      </Button>

      <DialogFooter>
        <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
          Cancelar
        </Button>
        <Button type='submit' disabled={mutation.isPending || hasRestrictedRow}>
          {mutation.isPending
            ? 'Registrando…'
            : `Registrar ${fields.length} paquete${fields.length !== 1 ? 's' : ''}`}
        </Button>
      </DialogFooter>
    </form>
  )
}
