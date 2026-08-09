import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { manualEntrySchema, type IAccessLog, type ManualEntryValues } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { EntryRequestModal } from '@/modules/access/components/EntryRequestModal'
import { ScanQrModal } from '@/modules/access/components/ScanQrModal'
import { UnitCombobox } from '@/modules/unit/components/UnitCombobox'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Skeleton } from 'boneyard-js/react'
import { Car, DoorOpen, LogOut, QrCode, UserRound, UserCheck } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'

/** Horas transcurridas desde `enteredAt` hasta ahora (lógica, no display). */
function hoursInside(enteredAt: string): number {
  const diffMs = Date.now() - new Date(enteredAt).getTime()
  return diffMs / (1000 * 60 * 60)
}

/**
 * Control de accesos — "en el condominio ahora" (§14.4): visitas con entrada
 * registrada y sin salida, registro manual de entrada y salida (checkout).
 */
export const AccessNowPage = () => {
  const queryClient = useQueryClient()
  const [manualOpen, setManualOpen] = useState(false)
  const [scanOpen, setScanOpen] = useState(false)
  const [requestOpen, setRequestOpen] = useState(false)

  const { data: inside = [], isLoading } = useQuery({
    queryKey: ['access', 'now'],
    queryFn: () => AccessLogResource.now(),
    refetchInterval: 30 * 1000,
  })

  const checkoutMutation = useMutation({
    mutationFn: (log: IAccessLog) => AccessLogResource.checkout(log.id),
    onSuccess: () => {
      toast.success('Salida registrada')
      void queryClient.invalidateQueries({ queryKey: ['access'] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo registrar la salida'
      ),
  })

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Control de Accesos'
        description='Quién está dentro del condominio ahora mismo'
        actions={
          <Link
            to='/visitor'
            className='inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-[18px] py-[9px] text-[13px] font-medium hover:border-[var(--ink-3)]'
          >
            Visitantes
          </Link>
        }
      />

      {/* Acción primaria de la caseta: escanear QR es lo primero y más grande;
          registrar entrada y solicitar autorización quedan como secundarias. */}
      <PermissionGuard permission='create.access_log'>
        <div className='space-y-3 rounded-[16px] border border-border bg-card p-4 shadow-sm sm:p-5'>
          <Button
            size='lg'
            className='h-16 w-full rounded-[14px] text-base font-semibold'
            onClick={() => setScanOpen(true)}
          >
            <QrCode className='size-6' />
            Escanear QR
          </Button>
          <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
            <Button variant='outline' className='rounded-full' onClick={() => setManualOpen(true)}>
              <DoorOpen className='size-4' />
              Registrar entrada
            </Button>
            <Button variant='outline' className='rounded-full' onClick={() => setRequestOpen(true)}>
              <UserCheck className='size-4' />
              Solicitar autorización
            </Button>
          </div>
        </div>
      </PermissionGuard>

      {isLoading ? (
        <Skeleton name='access-now-cards' loading={true}>
          <div className='grid grid-cols-1 gap-3.5 md:grid-cols-2 lg:grid-cols-3'>
            {[1, 2, 3].map((i) => (
              <div key={i} className='h-[120px] rounded-[14px] border border-border bg-card' />
            ))}
          </div>
        </Skeleton>
      ) : inside.length === 0 ? (
        <p className='rounded-[14px] border border-border bg-card px-6 py-12 text-center text-sm text-[var(--ink-3)]'>
          No hay visitas dentro del condominio en este momento.
        </p>
      ) : (
        <div className='grid grid-cols-1 gap-3.5 md:grid-cols-2 lg:grid-cols-3'>
          {inside.map((log) => (
            <div key={log.id} className='rounded-[14px] border border-border bg-card p-4 shadow-sm'>
              <div className='flex items-start gap-3'>
                <span className='grid size-10 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[var(--green)]'>
                  <UserRound className='size-5' />
                </span>
                <div className='min-w-0 flex-1'>
                  <p className='truncate text-[14px] font-semibold'>
                    {log.visitor_name ?? log.visitor?.name ?? 'Visitante'}
                  </p>
                  <p className='text-[12.5px] text-[var(--ink-3)]'>
                    {log.unit?.full_identifier ?? log.unit?.number ?? '—'}
                    {log.visitor_motive?.name ? ` · ${log.visitor_motive.name}` : ''}
                  </p>
                  <p className='mt-1 text-[12px] text-[var(--ink-4)]'>
                    Entró {log.entered_at ? displayFormatDate(log.entered_at) : '—'}
                  </p>
                  {log.entered_at && hoursInside(log.entered_at) >= 8 && (
                    <p className='mt-1 text-[12px] font-medium text-amber-600'>
                      Lleva {Math.floor(hoursInside(log.entered_at))}h dentro
                    </p>
                  )}
                  {log.plate && (
                    <p className='mt-1 flex items-center gap-1.5 text-[12px] text-[var(--ink-3)]'>
                      <Car className='size-3.5' />
                      <span className='rounded border border-border bg-[var(--surface-2)] px-1.5 font-mono'>
                        {log.plate}
                      </span>
                    </p>
                  )}
                </div>
              </div>
              <PermissionGuard permission='update.access_log'>
                <Button
                  variant='outline'
                  size='sm'
                  className='mt-3 w-full rounded-full text-[12.5px]'
                  onClick={() => checkoutMutation.mutate(log)}
                  disabled={checkoutMutation.isPending}
                >
                  <LogOut className='size-3.5' />
                  Registrar salida
                </Button>
              </PermissionGuard>
            </div>
          ))}
        </div>
      )}

      <ManualEntryModal isOpen={manualOpen} onClose={() => setManualOpen(false)} />
      <EntryRequestModal isOpen={requestOpen} onClose={() => setRequestOpen(false)} />
      <ScanQrModal
        isOpen={scanOpen}
        onClose={() => setScanOpen(false)}
        onScanned={() => void queryClient.invalidateQueries({ queryKey: ['access'] })}
        onFallbackToManual={() => {
          setScanOpen(false)
          setManualOpen(true)
        }}
      />
    </div>
  )
}

function ManualEntryModal({ isOpen, onClose }: { isOpen: boolean; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [photo, setPhoto] = useState<File | null>(null)

  const defaultValues = useMemo<ManualEntryValues>(
    () => ({
      unit_id: '',
      visitor_name: '',
      access_verification_id: '',
      visitor_motive_id: undefined,
      plate: '',
      id_document_type: undefined,
      id_document_number: '',
      authorized_by: '',
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
  } = useForm<ManualEntryValues>({
    resolver: zodResolver(manualEntrySchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) {
      reset(defaultValues)
      setPhoto(null)
    }
  }, [isOpen, reset, defaultValues])

  // Catálogos del formulario de entrada manual: NO se cargan al montar la página
  // (el modal vive montado pero cerrado). Se difieren hasta que el modal se abre.
  const { catalogs, enable: enableCatalogs } = useLazyCatalogs(
    useMemo(
      () => ({
        [CatalogType.ACCESS_VERIFICATION]: { _sort: 'order' },
        [CatalogType.VISITOR_MOTIVE]: { _sort: 'order' },
      }),
      []
    )
  )

  useEffect(() => {
    if (isOpen) enableCatalogs()
  }, [isOpen, enableCatalogs])

  const mutation = useMutation({
    mutationFn: (values: ManualEntryValues) =>
      AccessLogResource.manualEntry(
        {
          ...values,
          plate: values.plate || undefined,
          id_document_number: values.id_document_number || undefined,
          authorized_by: values.authorized_by || undefined,
        },
        photo ?? undefined
      ),
    onSuccess: () => {
      toast.success('Entrada registrada')
      void queryClient.invalidateQueries({ queryKey: ['access'] })
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo registrar la entrada'
      )
    },
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent
        className='max-h-[92vh] max-w-xl overflow-hidden p-0 flex flex-col'
        aria-describedby={undefined}
      >
        <DialogHeader className='shrink-0 px-6 pt-6'>
          <DialogTitle>Registrar entrada manual</DialogTitle>
        </DialogHeader>

        <form
          className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
          onSubmit={handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='entry-name' required>
                  Nombre del visitante
                </FieldLabel>
                <Input
                  id='entry-name'
                  {...register('visitor_name')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.visitor_name?.message}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='entry-unit' required>
                  Unidad que visita
                </FieldLabel>
                <UnitCombobox
                  id='entry-unit'
                  value={watch('unit_id')}
                  disabled={mutation.isPending}
                  onSelect={(unit) => setValue('unit_id', unit.id, { shouldValidate: true })}
                />
                <FieldError>{errors.unit_id?.message}</FieldError>
              </Field>

              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='entry-verification' required>
                  Verificación
                </FieldLabel>
                <Controller
                  name='access_verification_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='entry-verification'
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.ACCESS_VERIFICATION] ?? []}
                      isDisabled={mutation.isPending}
                      invalid={!!errors.access_verification_id}
                      placeholder='¿Cómo se verificó?'
                    />
                  )}
                />
                <FieldError>{errors.access_verification_id?.message}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='entry-motive'>Motivo</FieldLabel>
                <Controller
                  name='visitor_motive_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='entry-motive'
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? undefined)}
                      options={catalogs[CatalogType.VISITOR_MOTIVE] ?? []}
                      isDisabled={mutation.isPending}
                      placeholder='Seleccionar…'
                    />
                  )}
                />
              </Field>

              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='entry-plate'>Placa</FieldLabel>
                <Input
                  id='entry-plate'
                  placeholder='ABC-123-4'
                  {...register('plate')}
                  disabled={mutation.isPending}
                />
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='entry-doc-type'>Identificación</FieldLabel>
                <Controller
                  name='id_document_type'
                  control={control}
                  render={({ field }) => (
                    <Select
                      value={field.value || undefined}
                      onValueChange={field.onChange}
                      disabled={mutation.isPending}
                    >
                      <SelectTrigger id='entry-doc-type' className='w-full'>
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
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='entry-doc-num'>Núm. de documento</FieldLabel>
                <Input
                  id='entry-doc-num'
                  {...register('id_document_number')}
                  disabled={mutation.isPending}
                />
              </Field>

              <Field className='col-span-12'>
                <FieldLabel htmlFor='entry-authorized'>Autorizado por</FieldLabel>
                <Input
                  id='entry-authorized'
                  placeholder='Residente que autoriza'
                  {...register('authorized_by')}
                  disabled={mutation.isPending}
                />
              </Field>

              <Field className='col-span-12'>
                <FieldLabel htmlFor='entry-photo'>Foto del visitante (opcional)</FieldLabel>
                <Input
                  id='entry-photo'
                  type='file'
                  accept='image/*'
                  capture='environment'
                  disabled={mutation.isPending}
                  onChange={(e) => setPhoto(e.target.files?.[0] ?? null)}
                  className='cursor-pointer'
                />
              </Field>
            </div>
          </FieldGroup>
        </form>

        <DialogFooter className='shrink-0 px-6 pb-6'>
          <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={handleSubmit((values) => mutation.mutate(values))}
            disabled={mutation.isPending}
            className='min-w-[150px]'
          >
            {mutation.isPending ? 'Registrando…' : 'Registrar entrada'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
