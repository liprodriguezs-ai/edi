import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  QR_TYPE,
  visitorFormSchema,
  type IVisitor,
  type VisitorFormValues,
} from '@/modules/access/schema'
import { VisitorResource } from '@/modules/access/services/AccessResource'
import { ResidentUnitField } from '@/modules/access/components/ResidentUnitField'
import { useMyRestrictions } from '@/modules/collections/hooks/useMyRestrictions'
import { UnitCombobox } from '@/modules/unit/components/UnitCombobox'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { displayMoney } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { AlertCircle } from 'lucide-react'
import { useEffect, useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface VisitorFormModalProps {
  isOpen: boolean
  onClose: () => void
  /** Recibe la visita creada (con qr_token) para mostrar el QR a continuación. */
  onCreated: (visitor: IVisitor) => void
}

/**
 * Registro de una visita: captura los datos y genera el QR (POST /api/visitor).
 * La unidad se valida en el backend contra la pertenencia del solicitante
 * (regla CallerBelongsToUnit); el alcance del campo de unidad se deriva del
 * MISMO criterio para que el formulario solo ofrezca lo que el backend acepta.
 *
 * Thin wrapper that only mounts `VisitorFormModalBody` while open (mirrors
 * `ReserveAmenityWizard`) — the parent page keeps this component always in
 * its tree, so without this early return `useMyRestrictions()` inside the
 * body would fire `GET /my-restrictions` on every list render, not just when
 * the resident actually opens the form (infra-cost rule, design §8.2).
 */
export function VisitorFormModal({ isOpen, onClose, onCreated }: VisitorFormModalProps) {
  if (!isOpen) return null
  return <VisitorFormModalBody isOpen={isOpen} onClose={onClose} onCreated={onCreated} />
}

function VisitorFormModalBody({ isOpen, onClose, onCreated }: VisitorFormModalProps) {
  const defaultValues = useMemo<VisitorFormValues>(
    () => ({
      unit_id: '',
      name: '',
      visitor_motive_id: '',
      qr_type_id: '',
      qr_expires_at: '',
      arrives_by_vehicle: false,
      plate: '',
      vehicle_model: '',
      id_document_type: undefined,
      id_document_number: '',
      notes: '',
    }),
    []
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    setValue,
    watch,
    formState: { errors },
  } = useForm<VisitorFormValues>({
    resolver: zodResolver(visitorFormSchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) reset(defaultValues)
  }, [isOpen, reset, defaultValues])

  // Espejo EXACTO de la regla del backend (Modules/Access/app/Rules/CallerBelongsToUnit):
  // staff = tokenCan('*') || tokenCan('read.access_log') → puede registrar visitas de
  // cualquier unidad; el resto, solo las suyas. Se decide ACÁ ADENTRO y no vía prop a
  // propósito: este mismo modal se abre desde el dashboard del residente Y desde la lista
  // de Visitantes (a la que un residente también llega), así que atarlo al call site deja
  // agujeros — un residente terminaba viendo el buscador de todo el condominio.
  const canTargetAnyUnit = usePermissions('read.access_log')

  const { unitState, notice } = useMyRestrictions()
  // Delinquency restriction (design §8.2, R-DELQ-1): gated on the SELECTED
  // unit only, never an aggregate — a resident with a current unit and an
  // overdue unit must still be able to register a visit for the current one.
  const selectedUnitId = watch('unit_id')
  const restriction = selectedUnitId ? unitState(selectedUnitId) : undefined
  const isVisitorAccessRestricted = restriction?.features.visitor_access === true

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.VISITOR_MOTIVE]: { _sort: 'order' },
        [CatalogType.QR_TYPE]: { _sort: 'order' },
      }),
      []
    )
  )

  // Preselecciona el primer tipo de QR cuando carga el catálogo (suele ser único).
  const qrTypes = catalogs[CatalogType.QR_TYPE]
  const selectedQrType = watch('qr_type_id')
  useEffect(() => {
    if (isOpen && !selectedQrType && qrTypes && qrTypes.length > 0) {
      setValue('qr_type_id', qrTypes[0].id, { shouldValidate: false })
    }
  }, [isOpen, selectedQrType, qrTypes, setValue])

  const arrivesByVehicle = watch('arrives_by_vehicle')

  // El QR de tiempo y el de evento llevan una vigencia (qr_expires_at). El de
  // un solo uso (SINGLE_ENTRY) no: se consume al primer escaneo.
  const needsExpiry = selectedQrType === QR_TYPE.TIME_BASED || selectedQrType === QR_TYPE.EVENT

  // Al cambiar a un tipo que no usa vigencia, limpia el campo para no arrastrar
  // una fecha (posiblemente pasada) si el usuario vuelve a tiempo/evento.
  useEffect(() => {
    if (!needsExpiry) {
      setValue('qr_expires_at', '', { shouldValidate: false })
    }
  }, [needsExpiry, setValue])

  const mutation = useMutation({
    mutationFn: (values: VisitorFormValues) => {
      const needsExpiryAtSubmit =
        values.qr_type_id === QR_TYPE.TIME_BASED || values.qr_type_id === QR_TYPE.EVENT
      // El input datetime-local entrega 'YYYY-MM-DDTHH:mm' (hora local); lo
      // convertimos a ISO 8601 para el backend. Se omite si el tipo no la requiere.
      const expiresAtIso =
        needsExpiryAtSubmit && values.qr_expires_at
          ? new Date(values.qr_expires_at).toISOString()
          : undefined

      return VisitorResource.create({
        ...values,
        qr_expires_at: expiresAtIso,
        plate: values.arrives_by_vehicle ? values.plate || undefined : undefined,
        vehicle_model: values.arrives_by_vehicle ? values.vehicle_model || undefined : undefined,
        id_document_number: values.id_document_number || undefined,
        notes: values.notes || undefined,
      })
    },
    onSuccess: (created) => {
      toast.success('Visita registrada — se generó el QR')
      onCreated(created)
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo registrar la visita'
      )
    },
  })

  // Delinquency guard (design §8.2): the trigger button and the modal itself
  // always open normally (R-DELQ-11) — only the actual submit is stopped for
  // a restricted unit. Guarded here (not just via `disabled`) because a
  // native form submit (Enter key) bypasses a disabled button.
  const submitVisitor = handleSubmit((values) => {
    if (isVisitorAccessRestricted) return
    mutation.mutate(values)
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent
        className='max-h-[92vh] max-w-xl overflow-hidden p-0 flex flex-col'
        aria-describedby={undefined}
      >
        <DialogHeader className='shrink-0 px-6 pt-6'>
          <DialogTitle>Registrar visita</DialogTitle>
        </DialogHeader>

        <form className='min-h-0 flex-1 overflow-y-auto px-6 py-2' onSubmit={submitVisitor}>
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='visitor-name' required>
                  Nombre del visitante
                </FieldLabel>
                <Input id='visitor-name' {...register('name')} disabled={mutation.isPending} />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='visitor-unit' required>
                  Unidad que visita
                </FieldLabel>
                {!canTargetAnyUnit ? (
                  <ResidentUnitField
                    id='visitor-unit'
                    value={selectedUnitId}
                    disabled={mutation.isPending}
                    invalid={!!errors.unit_id}
                    onChange={(unitId) => setValue('unit_id', unitId, { shouldValidate: true })}
                  />
                ) : (
                  <UnitCombobox
                    id='visitor-unit'
                    value={selectedUnitId}
                    disabled={mutation.isPending}
                    onSelect={(unit) => setValue('unit_id', unit.id, { shouldValidate: true })}
                  />
                )}
                <FieldError>{errors.unit_id?.message}</FieldError>
              </Field>

              {/* Full width: the notice is prose, and half a column wraps it into
                  an unreadable ribbon. Rendered after the unit picker — which stays
                  visible so a resident holding several units can switch to one that
                  is current (R-DELQ-1: gating is per unit, never per resident). */}
              {isVisitorAccessRestricted && (
                <div
                  role='alert'
                  className='col-span-12 flex items-start gap-3 rounded-lg bg-destructive/5 p-4 text-sm text-destructive'
                >
                  <AlertCircle className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
                  <div className='space-y-1'>
                    <p className='font-medium'>
                      {restriction?.overdue_balance
                        ? `Adeudo vencido: ${displayMoney(restriction.overdue_balance)}.`
                        : 'Esta unidad tiene un adeudo vencido.'}
                    </p>
                    {notice && <p className='text-destructive/90'>{notice}</p>}
                  </div>
                </div>
              )}

              {/* The rest of the form is hidden while the selected unit is restricted:
                  the visit cannot be registered, so asking for motive, QR, ID and notes
                  only wastes the resident's time. The unit picker above stays available. */}
              {!isVisitorAccessRestricted && (
                <>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='visitor-motive' required>
                      Motivo
                    </FieldLabel>
                    <Controller
                      name='visitor_motive_id'
                      control={control}
                      render={({ field }) => (
                        <SearchSelect
                          id='visitor-motive'
                          value={field.value}
                          onChange={(v) => field.onChange((v as string | null) ?? '')}
                          options={catalogs[CatalogType.VISITOR_MOTIVE] ?? []}
                          autoSelectSingle
                          isDisabled={mutation.isPending}
                          invalid={!!errors.visitor_motive_id}
                          placeholder='Seleccionar…'
                        />
                      )}
                    />
                    <FieldError>{errors.visitor_motive_id?.message}</FieldError>
                  </Field>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='visitor-qr-type' required>
                      Tipo de QR
                    </FieldLabel>
                    <Controller
                      name='qr_type_id'
                      control={control}
                      render={({ field }) => (
                        <SearchSelect
                          id='visitor-qr-type'
                          value={field.value}
                          onChange={(v) => field.onChange((v as string | null) ?? '')}
                          options={catalogs[CatalogType.QR_TYPE] ?? []}
                          isDisabled={mutation.isPending}
                          invalid={!!errors.qr_type_id}
                          placeholder='Seleccionar…'
                        />
                      )}
                    />
                    <FieldError>{errors.qr_type_id?.message}</FieldError>
                  </Field>

                  {needsExpiry && (
                    <Field className='col-span-12'>
                      <FieldLabel
                        htmlFor='visitor-qr-expires'
                        required={selectedQrType === QR_TYPE.EVENT}
                      >
                        {selectedQrType === QR_TYPE.EVENT
                          ? 'Fecha y hora del evento · válido solo ese día'
                          : 'Válido hasta · fecha y hora'}
                      </FieldLabel>
                      <Input
                        id='visitor-qr-expires'
                        type='datetime-local'
                        {...register('qr_expires_at')}
                        disabled={mutation.isPending}
                      />
                      <FieldError>{errors.qr_expires_at?.message}</FieldError>
                      <p className='text-[12px] text-[var(--ink-3)]'>
                        {selectedQrType === QR_TYPE.EVENT
                          ? 'QR de evento: múltiples ingresos SOLO el día indicado, hasta la hora de corte. La fecha es obligatoria.'
                          : 'QR por tiempo: múltiples ingresos hasta la fecha y hora indicada (puede abarcar varios días). Si lo dejás vacío, vale 24 horas.'}
                      </p>
                    </Field>
                  )}

                  <Field className='col-span-12'>
                    <label className='flex items-center gap-3 text-sm'>
                      <Controller
                        name='arrives_by_vehicle'
                        control={control}
                        render={({ field }) => (
                          <Switch
                            checked={field.value}
                            onCheckedChange={field.onChange}
                            disabled={mutation.isPending}
                          />
                        )}
                      />
                      Llega en vehículo
                    </label>
                  </Field>

                  {arrivesByVehicle && (
                    <>
                      <Field className='col-span-6'>
                        <FieldLabel htmlFor='visitor-plate' required>
                          Placa
                        </FieldLabel>
                        <Input
                          id='visitor-plate'
                          placeholder='ABC-123-4'
                          {...register('plate')}
                          disabled={mutation.isPending}
                        />
                        <FieldError>{errors.plate?.message}</FieldError>
                      </Field>
                      <Field className='col-span-6'>
                        <FieldLabel htmlFor='visitor-vehicle-model'>Modelo del vehículo</FieldLabel>
                        <Input
                          id='visitor-vehicle-model'
                          {...register('vehicle_model')}
                          disabled={mutation.isPending}
                        />
                      </Field>
                    </>
                  )}

                  <Field className='col-span-6 md:col-span-4'>
                    <FieldLabel htmlFor='visitor-doc-type'>Identificación</FieldLabel>
                    <Controller
                      name='id_document_type'
                      control={control}
                      render={({ field }) => (
                        <Select
                          value={field.value || undefined}
                          onValueChange={field.onChange}
                          disabled={mutation.isPending}
                        >
                          <SelectTrigger id='visitor-doc-type' className='w-full'>
                            <SelectValue placeholder='Tipo…' />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value='ine'>INE</SelectItem>
                            <SelectItem value='passport'>Pasaporte</SelectItem>
                            <SelectItem value='driver_license'>Licencia</SelectItem>
                            <SelectItem value='other'>Otra</SelectItem>
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </Field>
                  <Field className='col-span-6 md:col-span-8'>
                    <FieldLabel htmlFor='visitor-doc-num'>Núm. de documento</FieldLabel>
                    <Input
                      id='visitor-doc-num'
                      {...register('id_document_number')}
                      disabled={mutation.isPending}
                    />
                  </Field>

                  <Field className='col-span-12'>
                    <FieldLabel htmlFor='visitor-notes'>Notas</FieldLabel>
                    <Textarea
                      id='visitor-notes'
                      rows={2}
                      className='resize-none'
                      {...register('notes')}
                      disabled={mutation.isPending}
                    />
                  </Field>
                </>
              )}
            </div>
          </FieldGroup>
        </form>

        <DialogFooter className='shrink-0 px-6 pb-6'>
          <Button
            type='button'
            variant='outline'
            onClick={onClose}
            disabled={mutation.isPending}
            className='min-w-[80px]'
          >
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={submitVisitor}
            disabled={mutation.isPending || isVisitorAccessRestricted}
            className='min-w-[120px]'
          >
            {mutation.isPending ? 'Registrando…' : 'Registrar visita'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
