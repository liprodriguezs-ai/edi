import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import {
  appointmentFormSchema,
  walkInFormSchema,
  type AppointmentFormValues,
  type IAppointment,
} from '@/modules/appointment/schema'
import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { childFullName, type IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Callout } from '@/shared/components/atoms/Callout'
import { Modal } from '@/shared/components/Modal'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { toast } from '@/shared/hooks/useToast'
import { tenantDateTimeLocalToUtc } from '@/shared/utils/tenantTime'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQueryClient } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { useCallback, useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

export type AppointmentFormMode = 'book' | 'walk_in'

/** Valores con los que se abre el formulario (desde un hueco, un niño o una sugerencia). */
export interface AppointmentFormPrefill {
  child?: Pick<IChild, 'id' | 'first_name' | 'last_name_paternal' | 'last_name_maternal'> | null
  practitionerId?: string
  appointmentTypeId?: string
  /** Hora de pared del tenant `YYYY-MM-DDTHH:mm`. */
  startsAtLocal?: string
  followUpId?: string | null
  /** Motivo de una sugerencia, mostrado como ayuda. */
  hint?: string
}

interface AppointmentFormSheetProps {
  isOpen: boolean
  mode: AppointmentFormMode
  prefill?: AppointmentFormPrefill
  onClose: () => void
  onSaved?: (_appointment: IAppointment) => void
}

const DEFAULT_DURATION = 30

/**
 * Agendar cita (S3) o "Registrar sin cita" (S5). El wrapper no monta nada
 * mientras está cerrado, así los catálogos cargan al abrir (§2.12).
 */
export function AppointmentFormSheet(props: AppointmentFormSheetProps) {
  if (!props.isOpen) return null
  return <AppointmentFormBody {...props} />
}

type PractitionerOption = ICatalogItem & { user?: { name?: string } | null }

function practitionerLabel(option: PractitionerOption): string {
  return option.user?.name || option.name || ''
}

function AppointmentFormBody({ mode, prefill, onClose, onSaved }: AppointmentFormSheetProps) {
  const isWalkIn = mode === 'walk_in'
  const timeZone = useTenantTimeZone()
  const queryClient = useQueryClient()
  const [isSaving, setIsSaving] = useState(false)
  const [durationTouched, setDurationTouched] = useState(false)
  // Remote SearchSelect only knows the label of what `selectedOptions` holds, so
  // remember every searched child and hand back the picked one.
  const searchedChildren = useRef(new Map<string, IChild>())
  const [pickedChild, setPickedChild] = useState<IChild | null>((prefill?.child as IChild | undefined) ?? null)

  const searchChildren = useCallback(async (term: string) => {
    const children = await ChildResource.searchByTerm(term)
    for (const child of children) searchedChildren.current.set(child.id, child)
    return children
  }, [])

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs({
    [CatalogType.PRACTITIONER_PROFILE]: { _with: 'user' },
    [CatalogType.APPOINTMENT_TYPE]: { _sort: 'sort_order' },
  })

  const appointmentTypes = useMemo(
    () => (catalogs[CatalogType.APPOINTMENT_TYPE] ?? []) as Array<ICatalogItem & { default_duration_minutes?: number }>,
    [catalogs]
  )

  const {
    register,
    handleSubmit,
    control,
    setValue,
    setError,
    formState: { errors },
  } = useForm<AppointmentFormValues>({
    resolver: zodResolver(isWalkIn ? walkInFormSchema : appointmentFormSchema),
    defaultValues: {
      child_id: prefill?.child?.id ?? '',
      practitioner_id: prefill?.practitionerId ?? '',
      appointment_type_id: prefill?.appointmentTypeId ?? '',
      starts_at_local: prefill?.startsAtLocal ?? '',
      duration_minutes: DEFAULT_DURATION,
      notes: '',
      follow_up_id: prefill?.followUpId ?? undefined,
    },
  })

  const handleTypeChange = (typeId: string) => {
    setValue('appointment_type_id', typeId, { shouldValidate: true })
    if (durationTouched) return
    const minutes = appointmentTypes.find((type) => type.id === typeId)?.default_duration_minutes
    if (minutes) setValue('duration_minutes', minutes)
  }

  const save = async (values: AppointmentFormValues): Promise<IAppointment | null> => {
    if (isWalkIn) {
      return AppointmentResource.walkIn({
        child_id: values.child_id,
        practitioner_id: values.practitioner_id,
        appointment_type_id: values.appointment_type_id,
        notes: values.notes || undefined,
      })
    }

    const startsAt = tenantDateTimeLocalToUtc(values.starts_at_local, timeZone)
    if (!startsAt.ok) {
      setError('starts_at_local', { message: 'La hora no existe en la zona horaria de la clínica.' })
      return null
    }

    return AppointmentResource.create({
      child_id: values.child_id,
      practitioner_id: values.practitioner_id,
      appointment_type_id: values.appointment_type_id,
      starts_at: startsAt.value,
      duration_minutes: values.duration_minutes,
      notes: values.notes || undefined,
      follow_up_id: values.follow_up_id || undefined,
    })
  }

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      const appointment = await save(values)
      if (!appointment) return
      toast.success(isWalkIn ? 'Niño registrado en sala de espera' : 'Cita agendada')
      void queryClient.invalidateQueries({ queryKey: ['appointment'] })
      onSaved?.(appointment)
      onClose()
    } catch (err) {
      if (err instanceof ApiError) {
        const startsAtError = err.payload?.errors?.starts_at
        if (Array.isArray(startsAtError) && startsAtError[0]) {
          setError('starts_at_local', { message: String(startsAtError[0]) })
          return
        }
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error(isWalkIn ? 'No se pudo registrar al niño' : 'No se pudo agendar la cita')
      }
    } finally {
      setIsSaving(false)
    }
  })

  const submitLabel = isWalkIn ? 'Registrar llegada' : 'Agendar'

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={isWalkIn ? 'Registrar sin cita' : 'Agendar cita'}
      description={
        isWalkIn
          ? 'El niño pasa directo a la sala de espera del profesional.'
          : 'La visita y su folio se generan cuando inicia la atención.'
      }
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} loading={isSaving} className="min-w-[100px]">
            {isSaving ? 'Guardando…' : submitLabel}
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <RequiredFieldsLegend />
        {prefill?.hint && (
          <Callout icon={Info} tone="info" className="mb-4">
            {prefill.hint}
          </Callout>
        )}
        <FieldGroup>
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12">
              <FieldLabel htmlFor="appointment-child_id" required>
                Niño
              </FieldLabel>
              <Controller
                name="child_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<IChild>
                    id="appointment-child_id"
                    value={field.value || null}
                    onChange={(v) => {
                      const id = (v as string | null) ?? ''
                      setPickedChild(searchedChildren.current.get(id) ?? null)
                      field.onChange(id)
                    }}
                    loadOptions={searchChildren}
                    selectedOptions={pickedChild ? [pickedChild] : undefined}
                    getOptionLabel={(child) => childFullName(child)}
                    isDisabled={isSaving || Boolean(prefill?.child)}
                    invalid={Boolean(errors.child_id)}
                    placeholder="Buscar por nombre o CURP…"
                  />
                )}
              />
              <FieldError>{errors.child_id?.message}</FieldError>
            </Field>

            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="appointment-appointment_type_id" required>
                Tipo de cita
              </FieldLabel>
              <Controller
                name="appointment_type_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="appointment-appointment_type_id"
                    value={field.value || null}
                    onChange={(v) => handleTypeChange((v as string | null) ?? '')}
                    options={appointmentTypes}
                    isDisabled={isSaving}
                    isLoading={isLoadingCatalogs}
                    invalid={Boolean(errors.appointment_type_id)}
                    placeholder="Seleccionar…"
                  />
                )}
              />
              <FieldError>{errors.appointment_type_id?.message}</FieldError>
            </Field>

            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="appointment-practitioner_id" required>
                Profesional
              </FieldLabel>
              <Controller
                name="practitioner_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<PractitionerOption>
                    id="appointment-practitioner_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={(catalogs[CatalogType.PRACTITIONER_PROFILE] ?? []) as PractitionerOption[]}
                    getOptionLabel={practitionerLabel}
                    isDisabled={isSaving}
                    isLoading={isLoadingCatalogs}
                    invalid={Boolean(errors.practitioner_id)}
                    placeholder="Seleccionar…"
                  />
                )}
              />
              <FieldError>{errors.practitioner_id?.message}</FieldError>
            </Field>

            {!isWalkIn && (
              <>
                <Field className="col-span-12 sm:col-span-8">
                  <FieldLabel htmlFor="appointment-starts_at_local" required>
                    Fecha y hora
                  </FieldLabel>
                  <Input
                    id="appointment-starts_at_local"
                    type="datetime-local"
                    step={300}
                    disabled={isSaving}
                    aria-invalid={Boolean(errors.starts_at_local)}
                    {...register('starts_at_local')}
                  />
                  <FieldDescription>Hora de la clínica.</FieldDescription>
                  <FieldError>{errors.starts_at_local?.message}</FieldError>
                </Field>
                <Field className="col-span-12 sm:col-span-4">
                  <FieldLabel htmlFor="appointment-duration_minutes" required>
                    Duración (min)
                  </FieldLabel>
                  <Input
                    id="appointment-duration_minutes"
                    type="number"
                    inputMode="numeric"
                    min={5}
                    max={480}
                    step={5}
                    disabled={isSaving}
                    aria-invalid={Boolean(errors.duration_minutes)}
                    {...register('duration_minutes', {
                      valueAsNumber: true,
                      onChange: () => setDurationTouched(true),
                    })}
                  />
                  <FieldError>{errors.duration_minutes?.message}</FieldError>
                </Field>
              </>
            )}

            <Field className="col-span-12">
              <FieldLabel htmlFor="appointment-notes">Notas</FieldLabel>
              <Textarea
                id="appointment-notes"
                rows={3}
                placeholder="Ej. traer cartilla de vacunación"
                disabled={isSaving}
                {...register('notes')}
              />
              <FieldError>{errors.notes?.message}</FieldError>
            </Field>
          </div>
        </FieldGroup>
      </form>
    </Modal>
  )
}
