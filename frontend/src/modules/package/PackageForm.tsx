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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import type { IPackage, PackageFormValues } from '@/modules/package/schema'
import { packageFormSchema } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { BulkIntakeForm } from '@/modules/package/components/BulkIntakeForm'
import { usePackageCatalogOptions } from '@/modules/package/hooks/usePackageCatalogOptions'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useCallback, useRef, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'

// Sentinel for the free-text "Otro" carrier option. radix Select.Item cannot use
// an empty-string value, so a non-empty sentinel is mapped back to undefined.
const CARRIER_OTHER_SENTINEL = '__other'

// ─── Single intake form ───────────────────────────────────────────────────────

const EMPTY_SINGLE: PackageFormValues = {
  unit_id: '',
  package_carrier_id: undefined,
  carrier_other: undefined,
  package_type_id: undefined,
  recipient_name: undefined,
  recipient_user_id: undefined,
  tracking_number: undefined,
  description: undefined,
  storage_location: undefined,
}

interface SingleFormProps {
  onCreated: (_pkg: IPackage) => void
  onClose: () => void
}

function SingleIntakeForm({ onCreated, onClose }: SingleFormProps) {
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

  const photoRef = useRef<HTMLInputElement>(null)
  const [photoFile, setPhotoFile] = useState<File | null>(null)

  const {
    register,
    handleSubmit,
    control,
    reset,
    setValue,
    formState: { errors },
  } = useForm<PackageFormValues>({
    resolver: zodResolver(packageFormSchema),
    defaultValues: EMPTY_SINGLE,
  })

  // When "Otro" is selected, carrier_other becomes a defined string (possibly empty)
  // and package_carrier_id is cleared, so the backend receives a valid free-text carrier
  // instead of an invalid sentinel UUID.
  const carrierOther = useWatch({ control, name: 'carrier_other' })
  const carrierIsOther = carrierOther !== undefined

  // Delinquency guard (design §8.3): the unit stays selectable — only submit
  // is stopped for a restricted unit, mirroring the server-side rule.
  const selectedUnitId = useWatch({ control, name: 'unit_id' })
  const isUnitRestricted = Boolean(selectedUnitId) && restrictedUnitIds.has(selectedUnitId)

  const mutation = useMutation({
    mutationFn: async (values: PackageFormValues) => {
      if (photoFile) {
        const form = new FormData()
        const entries = Object.entries(values) as [string, unknown][]
        for (const [k, v] of entries) {
          if (v !== undefined && v !== null && v !== '') {
            form.append(k, String(v))
          }
        }
        form.append('photo', photoFile)
        return PackageResource.create(form)
      }
      return PackageResource.create(values)
    },
    onSuccess: (pkg) => {
      toast.success('Paquete registrado')
      reset(EMPTY_SINGLE)
      setPhotoFile(null)
      onCreated(pkg)
    },
    onError: (error) => {
      const { title } = formatApiErrorMessage(
        error instanceof ApiError ? error : new ApiError('Error', 500)
      )
      toast.error(title)
    },
  })

  const submitSingle = handleSubmit((values) => {
    // Delinquency guard (design §8.3): the unit stays selectable — only the
    // submit itself is stopped here (defense in depth vs. Enter-key submit,
    // the backend 403 is the ultimate authority).
    if (restrictedUnitIds.has(values.unit_id)) return
    mutation.mutate(values)
  })

  return (
    <form onSubmit={submitSingle} className='space-y-4'>
      <RequiredFieldsLegend />
      {/* Unit */}
      <Field>
        <FieldLabel htmlFor='sf-unit' required>
          Unidad
        </FieldLabel>
        <Controller
          name='unit_id'
          control={control}
          render={({ field }) => (
            <Select
              value={field.value}
              onValueChange={field.onChange}
              onOpenChange={(open) => {
                if (open) enableUnits()
              }}
            >
              <SelectTrigger id='sf-unit' className={errors.unit_id ? 'border-destructive' : ''}>
                <SelectValue placeholder={loadingUnits ? 'Cargando…' : 'Seleccionar unidad'} />
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
        {errors.unit_id && <p className='text-[12px] text-destructive'>{errors.unit_id.message}</p>}
        {isUnitRestricted && (
          <p className='text-[12px] font-medium text-amber-600'>
            Esta unidad no puede recibir paquetes.
          </p>
        )}
      </Field>

      {/* Carrier */}
      <Field>
        <FieldLabel htmlFor='sf-carrier'>Transportista</FieldLabel>
        <Controller
          name='package_carrier_id'
          control={control}
          render={({ field }) => (
            <Select
              value={carrierIsOther ? CARRIER_OTHER_SENTINEL : field.value || undefined}
              onValueChange={(v) => {
                if (v === CARRIER_OTHER_SENTINEL) {
                  field.onChange(undefined)
                  setValue('carrier_other', '')
                } else {
                  field.onChange(v)
                  setValue('carrier_other', undefined)
                }
              }}
              onOpenChange={(open) => {
                if (open) enableCarriers()
              }}
            >
              <SelectTrigger id='sf-carrier'>
                <SelectValue placeholder='Sin especificar' />
              </SelectTrigger>
              <SelectContent>
                {carrierOptions.map((c) => (
                  <SelectItem key={c.value} value={c.value}>
                    {c.label}
                  </SelectItem>
                ))}
                <SelectItem value={CARRIER_OTHER_SENTINEL}>Otro</SelectItem>
              </SelectContent>
            </Select>
          )}
        />
        {carrierIsOther && (
          <Input
            placeholder='Nombre del transportista'
            maxLength={100}
            {...register('carrier_other')}
          />
        )}
      </Field>

      {/* Type */}
      <Field>
        <FieldLabel htmlFor='sf-type'>Tipo de paquete</FieldLabel>
        <Controller
          name='package_type_id'
          control={control}
          render={({ field }) => (
            <Select
              value={field.value || undefined}
              onValueChange={(v) => field.onChange(v || undefined)}
              onOpenChange={(open) => {
                if (open) enableTypes()
              }}
            >
              <SelectTrigger id='sf-type'>
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
      <Field>
        <FieldLabel htmlFor='sf-recipient'>Nombre del destinatario</FieldLabel>
        <Input
          id='sf-recipient'
          placeholder='Nombre en el paquete'
          {...register('recipient_name')}
        />
      </Field>

      {/* Tracking */}
      <Field>
        <FieldLabel htmlFor='sf-tracking'>Número de rastreo</FieldLabel>
        <Input id='sf-tracking' placeholder='1Z999AA10123456784' {...register('tracking_number')} />
      </Field>

      {/* Storage location */}
      <Field>
        <FieldLabel htmlFor='sf-storage'>Ubicación de almacén</FieldLabel>
        <Input id='sf-storage' placeholder='Estante A-3' {...register('storage_location')} />
      </Field>

      {/* Description */}
      <Field>
        <FieldLabel htmlFor='sf-desc'>Descripción</FieldLabel>
        <Textarea
          id='sf-desc'
          placeholder='Paquete grande, frágil…'
          rows={2}
          {...register('description')}
        />
      </Field>

      {/* Photo */}
      <Field>
        <FieldLabel htmlFor='sf-photo'>Foto del paquete</FieldLabel>
        <input
          ref={photoRef}
          id='sf-photo'
          type='file'
          accept='image/jpeg,image/png,image/webp'
          capture='environment'
          className='block w-full text-sm text-muted-foreground file:mr-3 file:rounded-full file:border-0 file:bg-[var(--surface-2)] file:px-3 file:py-1.5 file:text-xs file:font-medium'
          onChange={(e) => setPhotoFile(e.target.files?.[0] ?? null)}
        />
        {photoFile && <p className='text-[11.5px] text-muted-foreground'>{photoFile.name}</p>}
      </Field>

      <DialogFooter>
        <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
          Cancelar
        </Button>
        <Button type='submit' disabled={mutation.isPending || isUnitRestricted}>
          {mutation.isPending ? 'Registrando…' : 'Registrar'}
        </Button>
      </DialogFooter>
    </form>
  )
}

// ─── Package form dialog ──────────────────────────────────────────────────────

type IntakeMode = 'single' | 'bulk'

interface PackageFormProps {
  isOpen: boolean
  onClose: () => void
  onCreated: (_pkg?: IPackage) => void
}

export function PackageForm({ isOpen, onClose, onCreated }: PackageFormProps) {
  const [mode, setMode] = useState<IntakeMode>('single')

  // Reset mode to 'single' when the dialog closes.
  // Use render-time state adjustment (not useEffect) to avoid cascading re-renders.
  const [prevIsOpen, setPrevIsOpen] = useState(isOpen)
  if (isOpen !== prevIsOpen) {
    setPrevIsOpen(isOpen)
    if (!isOpen) setMode('single')
  }

  const handleClose = useCallback(() => {
    setMode('single')
    onClose()
  }, [onClose])

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        if (!open) handleClose()
      }}
    >
      <DialogContent className='max-h-[90dvh] overflow-y-auto sm:max-w-[560px]'>
        <DialogHeader>
          <DialogTitle>Registrar paquete</DialogTitle>
        </DialogHeader>

        <Tabs value={mode} onValueChange={(v) => setMode(v as IntakeMode)} className='mb-2'>
          <TabsList className='w-full'>
            <TabsTrigger value='single' className='flex-1'>
              Individual
            </TabsTrigger>
            <TabsTrigger value='bulk' className='flex-1'>
              Lote (hasta 50)
            </TabsTrigger>
          </TabsList>
        </Tabs>

        {mode === 'single' ? (
          <SingleIntakeForm
            onCreated={(pkg) => {
              handleClose()
              onCreated(pkg)
            }}
            onClose={handleClose}
          />
        ) : (
          <BulkIntakeForm
            onCreated={() => {
              handleClose()
              onCreated(undefined)
            }}
            onClose={handleClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
